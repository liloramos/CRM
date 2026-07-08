<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\PrinterSetting;
use App\Models\PrintJob;
use App\Models\PrintJobEvent;
use App\Models\ReceiptTemplate;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PrintWorkflowService
{
    public function __construct(private readonly OrderWorkflowService $orders) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function generateTicket(Order $order, ?User $user = null, array $attributes = []): PrintJob
    {
        return DB::transaction(function () use ($order, $user, $attributes): PrintJob {
            $order = $this->lockOrder($order);
            $order->load($this->ticketRelations());

            $targetAudience = (string) ($attributes['target_audience'] ?? ReceiptTemplate::TARGET_KITCHEN);
            $template = $this->resolveTemplate($order, $attributes['receipt_template_id'] ?? null, $targetAudience);
            $printer = $this->resolvePrinter($order, $attributes['printer_setting_id'] ?? null);
            $copyNumber = ((int) $order->printJobs()->max('copy_number')) + 1;
            $parentPrintJobId = $attributes['parent_print_job_id'] ?? null;
            $isReprint = (bool) ($attributes['is_reprint'] ?? ($parentPrintJobId !== null || $copyNumber > 1));
            $payload = $this->ticketPayload($order, $template, $printer, $copyNumber, $isReprint, $targetAudience);
            $html = view($template?->view_name ?? 'printing.order-ticket', ['ticket' => $payload])->render();

            $job = PrintJob::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'receipt_template_id' => $template?->id,
                'printer_setting_id' => $printer?->id,
                'requested_by_user_id' => $user?->id,
                'parent_print_job_id' => $parentPrintJobId,
                'job_type' => PrintJob::TYPE_ORDER_TICKET,
                'target_audience' => $targetAudience,
                'status' => PrintJob::STATUS_PREVIEWED,
                'copy_number' => $copyNumber,
                'is_reprint' => $isReprint,
                'preview_url' => "/orders/{$order->id}/ticket/preview",
                'html_content' => $html,
                'text_content' => $this->plainTextTicket($payload),
                'rendered_payload' => $payload,
                'requested_at' => now(),
                'previewed_at' => now(),
            ]);

            $fromStatus = $order->print_status;
            $order->forceFill([
                'latest_print_job_id' => $job->id,
                'print_status' => Order::PRINT_STATUS_PREVIEWED,
                'ticket_generated_at' => now(),
                'print_error_message' => null,
            ])->save();

            $this->recordEvent(
                $order,
                $job,
                $user,
                $isReprint ? PrintJobEvent::EVENT_REPRINT_REQUESTED : PrintJobEvent::EVENT_TICKET_GENERATED,
                $fromStatus,
                Order::PRINT_STATUS_PREVIEWED,
                $isReprint ? 'Reprint preview generated.' : 'Ticket preview generated.',
                ['copy_number' => $copyNumber, 'target_audience' => $targetAudience],
            );

            $this->moveToReadyToPrintIfOpen($order->refresh(), $user, $job, $isReprint);

            return $job->refresh();
        });
    }

    public function markPrinting(PrintJob $printJob, ?User $user = null): PrintJob
    {
        return DB::transaction(function () use ($printJob, $user): PrintJob {
            $printJob = $this->lockPrintJob($printJob);
            $order = $this->lockOrder($printJob->order()->firstOrFail());
            $fromStatus = $printJob->status;

            $printJob->forceFill([
                'status' => PrintJob::STATUS_PRINTING,
                'printing_started_at' => now(),
                'error_message' => null,
            ])->save();

            $this->syncOrderPrintStatus($order, $printJob, Order::PRINT_STATUS_PRINTING);
            $this->recordEvent($order, $printJob, $user, PrintJobEvent::EVENT_PRINT_STARTED, $fromStatus, PrintJob::STATUS_PRINTING);

            return $printJob->refresh();
        });
    }

    public function markPrinted(PrintJob $printJob, ?User $user = null): PrintJob
    {
        return DB::transaction(function () use ($printJob, $user): PrintJob {
            $printJob = $this->lockPrintJob($printJob);
            $order = $this->lockOrder($printJob->order()->firstOrFail());
            $fromStatus = $printJob->status;
            $printedAt = now();

            $printJob->forceFill([
                'status' => PrintJob::STATUS_PRINTED,
                'printed_by_user_id' => $user?->id,
                'printed_at' => $printedAt,
                'error_message' => null,
            ])->save();

            $order->forceFill([
                'latest_print_job_id' => $printJob->id,
                'print_status' => Order::PRINT_STATUS_PRINTED,
                'printed_at' => $printedAt,
                'print_error_message' => null,
            ])->save();

            $this->recordEvent($order, $printJob, $user, PrintJobEvent::EVENT_PRINTED, $fromStatus, PrintJob::STATUS_PRINTED);
            $this->moveToPrintedIfOpen($order->refresh(), $user, $printJob);

            return $printJob->refresh();
        });
    }

    public function failPrint(PrintJob $printJob, ?User $user = null, ?string $message = null): PrintJob
    {
        return DB::transaction(function () use ($printJob, $user, $message): PrintJob {
            $printJob = $this->lockPrintJob($printJob);
            $order = $this->lockOrder($printJob->order()->firstOrFail());
            $fromStatus = $printJob->status;
            $errorMessage = $message ?: 'Print failed during browser or driver workflow.';

            $printJob->forceFill([
                'status' => PrintJob::STATUS_FAILED,
                'error_message' => $errorMessage,
                'failed_at' => now(),
            ])->save();

            $order->forceFill([
                'latest_print_job_id' => $printJob->id,
                'print_status' => Order::PRINT_STATUS_FAILED,
                'print_error_message' => $errorMessage,
            ])->save();

            $this->recordEvent($order, $printJob, $user, PrintJobEvent::EVENT_PRINT_FAILED, $fromStatus, PrintJob::STATUS_FAILED, $errorMessage);

            return $printJob->refresh();
        });
    }

    public function markPrinterUnavailable(PrintJob $printJob, ?User $user = null, ?string $message = null): PrintJob
    {
        return DB::transaction(function () use ($printJob, $user, $message): PrintJob {
            $printJob = $this->lockPrintJob($printJob);
            $order = $this->lockOrder($printJob->order()->firstOrFail());
            $fromStatus = $printJob->status;
            $errorMessage = $message ?: 'Printer unavailable during operational workflow.';

            $printJob->forceFill([
                'status' => PrintJob::STATUS_PRINTER_UNAVAILABLE,
                'error_message' => $errorMessage,
                'failed_at' => now(),
            ])->save();

            $order->forceFill([
                'latest_print_job_id' => $printJob->id,
                'print_status' => Order::PRINT_STATUS_PRINTER_UNAVAILABLE,
                'print_error_message' => $errorMessage,
            ])->save();

            $this->recordEvent($order, $printJob, $user, PrintJobEvent::EVENT_PRINT_FAILED, $fromStatus, PrintJob::STATUS_PRINTER_UNAVAILABLE, $errorMessage);

            return $printJob->refresh();
        });
    }

    public function requestReprint(PrintJob $printJob, ?User $user = null, ?string $reason = null): PrintJob
    {
        return DB::transaction(function () use ($printJob, $user, $reason): PrintJob {
            $printJob = $this->lockPrintJob($printJob);
            $order = $this->lockOrder($printJob->order()->firstOrFail());
            $fromStatus = $printJob->status;

            $printJob->forceFill(['status' => PrintJob::STATUS_REPRINT_REQUESTED])->save();
            $order->forceFill(['print_status' => Order::PRINT_STATUS_REPRINT_REQUESTED])->save();

            $this->recordEvent(
                $order,
                $printJob,
                $user,
                PrintJobEvent::EVENT_REPRINT_REQUESTED,
                $fromStatus,
                PrintJob::STATUS_REPRINT_REQUESTED,
                $reason,
            );

            return $this->generateTicket($order->refresh(), $user, [
                'parent_print_job_id' => $printJob->id,
                'is_reprint' => true,
                'target_audience' => $printJob->target_audience,
                'receipt_template_id' => $printJob->receipt_template_id,
                'printer_setting_id' => $printJob->printer_setting_id,
            ]);
        });
    }

    public function markManualPrinted(Order $order, ?User $user = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $user, $reason): Order {
            $order = $this->lockOrder($order);
            $printedAt = now();
            $copyNumber = ((int) $order->printJobs()->max('copy_number')) + 1;

            $job = PrintJob::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'requested_by_user_id' => $user?->id,
                'printed_by_user_id' => $user?->id,
                'job_type' => PrintJob::TYPE_ORDER_TICKET,
                'target_audience' => ReceiptTemplate::TARGET_KITCHEN,
                'status' => PrintJob::STATUS_MANUAL_CONFIRMED,
                'copy_number' => $copyNumber,
                'is_reprint' => $copyNumber > 1,
                'requested_at' => $printedAt,
                'printed_at' => $printedAt,
            ]);

            $fromStatus = $order->print_status;
            $order->forceFill([
                'latest_print_job_id' => $job->id,
                'print_status' => Order::PRINT_STATUS_MANUAL_CONFIRMED,
                'printed_at' => $printedAt,
                'print_error_message' => null,
            ])->save();

            $this->recordEvent(
                $order,
                $job,
                $user,
                PrintJobEvent::EVENT_MANUAL_CONFIRMED,
                $fromStatus,
                Order::PRINT_STATUS_MANUAL_CONFIRMED,
                $reason,
            );

            $this->moveToPrintedIfOpen($order->refresh(), $user, $job);

            return $order->refresh();
        });
    }

    public function waivePrinting(Order $order, ?User $user, string $reason): Order
    {
        if (trim($reason) === '') {
            throw new DomainException('A reason is required to release preparation without printed ticket.');
        }

        return DB::transaction(function () use ($order, $user, $reason): Order {
            $order = $this->lockOrder($order);
            $fromStatus = $order->print_status;

            $order->forceFill([
                'print_status' => Order::PRINT_STATUS_WAIVED,
                'print_waived_at' => now(),
                'print_waived_by_user_id' => $user?->id,
                'print_waiver_reason' => $reason,
                'print_error_message' => null,
            ])->save();

            $this->recordEvent($order, null, $user, PrintJobEvent::EVENT_PRINT_WAIVED, $fromStatus, Order::PRINT_STATUS_WAIVED, $reason);
            $this->recordEvent($order, null, $user, PrintJobEvent::EVENT_ADVANCED_WITHOUT_PRINT, $fromStatus, Order::PRINT_STATUS_WAIVED, $reason);

            return $order->refresh();
        });
    }

    public function canReleaseForPreparation(Order $order): bool
    {
        return ! $order->print_required
            || in_array($order->print_status, Order::PREPARATION_PRINT_RELEASE_STATUSES, true);
    }

    /**
     * @return list<string>
     */
    private function ticketRelations(): array
    {
        return [
            'company.restaurantProfile',
            'payerCustomer',
            'deliveryAddress',
            'deliveryQuotes',
            'items.options',
            'payments',
            'creditMovements',
            'latestPrintJob',
        ];
    }

    private function lockOrder(Order $order): Order
    {
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function lockPrintJob(PrintJob $printJob): PrintJob
    {
        return PrintJob::query()->whereKey($printJob->id)->lockForUpdate()->firstOrFail();
    }

    private function resolveTemplate(Order $order, mixed $templateId, string $targetAudience): ?ReceiptTemplate
    {
        if ($templateId !== null) {
            $template = ReceiptTemplate::query()->findOrFail($templateId);

            if ((int) $template->company_id !== (int) $order->company_id) {
                throw new DomainException('Receipt template must belong to the same company as the order.');
            }

            return $template;
        }

        return ReceiptTemplate::query()
            ->where('company_id', $order->company_id)
            ->where('template_type', ReceiptTemplate::TYPE_ORDER_TICKET)
            ->where('target_audience', $targetAudience)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function resolvePrinter(Order $order, mixed $printerId): ?PrinterSetting
    {
        if ($printerId !== null) {
            $printer = PrinterSetting::query()->findOrFail($printerId);

            if ((int) $printer->company_id !== (int) $order->company_id) {
                throw new DomainException('Printer setting must belong to the same company as the order.');
            }

            return $printer;
        }

        return PrinterSetting::query()
            ->where('company_id', $order->company_id)
            ->where('status', PrinterSetting::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(
        Order $order,
        ?ReceiptTemplate $template,
        ?PrinterSetting $printer,
        int $copyNumber,
        bool $isReprint,
        string $targetAudience,
    ): array {
        $company = $order->company;
        $restaurantProfile = $company?->restaurantProfile;
        $currency = $order->currency ?: 'BRL';

        return [
            'restaurant' => [
                'name' => $restaurantProfile?->display_name ?? $company?->name ?? 'Restaurante',
            ],
            'title' => 'COMANDA DE PEDIDO',
            'target_audience' => $targetAudience,
            'copy_number' => $copyNumber,
            'is_reprint' => $isReprint,
            'printed_label' => $isReprint ? '2a VIA' : '1a VIA',
            'generated_at' => now()->format('d/m/Y H:i'),
            'template' => [
                'id' => $template?->id,
                'code' => $template?->code,
                'name' => $template?->name,
                'width_chars' => $template?->width_chars ?? 32,
            ],
            'printer' => [
                'id' => $printer?->id,
                'name' => $printer?->name,
                'model' => $printer?->printer_model,
                'print_mode' => $printer?->print_mode ?? PrinterSetting::PRINT_MODE_BROWSER_HTML,
            ],
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'daily_sequence' => $order->daily_sequence,
                'status' => $order->status,
                'origin_channel' => $order->origin_channel,
                'entry_mode' => $order->entry_mode,
                'fulfillment_type' => $order->fulfillment_type,
                'fulfillment_status' => $order->fulfillment_status,
                'created_at' => $order->created_at?->format('d/m/Y H:i'),
                'confirmed_at' => $order->confirmed_at?->format('d/m/Y H:i'),
                'is_fragmented' => (bool) $order->is_fragmented,
            ],
            'customer' => [
                'payer_name' => $order->payerCustomer?->name,
                'payer_phone' => $order->payerCustomer?->phone,
                'pickup_person_name' => $order->pickup_person_name,
                'pickup_person_phone' => $order->pickup_person_phone,
                'pickup_authorized_by' => $order->pickup_authorized_by,
                'delivery_recipient_name' => $order->delivery_recipient_name,
                'delivery_recipient_phone' => $order->delivery_recipient_phone,
            ],
            'fulfillment' => [
                'type' => $order->fulfillment_type,
                'delivery_status' => $order->delivery_status,
                'pickup_status' => $order->pickup_status,
                'delivery_reference' => $order->delivery_reference,
                'delivery_notes' => $order->delivery_notes,
                'pickup_notes' => $order->pickup_notes,
                'address_snapshot' => $order->delivery_address_snapshot,
            ],
            'items' => $order->items
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($item): array => [
                    'quantity' => $item->quantity,
                    'product_name' => $item->product_name,
                    'product_type' => $item->product_type,
                    'unit_price' => $this->money((int) $item->unit_price_cents, $currency),
                    'options_total' => $this->money((int) $item->options_total_cents, $currency),
                    'total_price' => $this->money((int) $item->total_price_cents, $currency),
                    'item_notes' => $item->item_notes,
                    'beneficiary_name' => $item->beneficiary_name,
                    'beneficiary_notes' => $item->beneficiary_notes,
                    'preferences' => $this->normalizeList($item->preferences),
                    'restrictions' => $this->normalizeList($item->restrictions),
                    'removed_ingredients' => $this->normalizeList($item->removed_ingredients),
                    'selected_components' => $this->normalizeList($item->selected_components),
                    'substitution_notes' => $item->substitution_notes,
                    'options' => $item->options
                        ->map(fn ($option): array => [
                            'name' => $option->name,
                            'option_type' => $option->option_type,
                            'quantity' => $option->quantity,
                            'price_delta' => $this->money((int) $option->price_delta_cents, $currency),
                            'total_price' => $this->money((int) $option->total_price_cents, $currency),
                        ])
                        ->values()
                        ->all(),
                ])
                ->all(),
            'payment' => [
                'method' => $order->payment_method,
                'status' => $order->payment_status,
                'subtotal' => $this->money((int) $order->subtotal_cents, $currency),
                'delivery_fee' => $this->money((int) ($order->delivery_fee_cents ?? 0), $currency),
                'adjustments' => $this->money((int) $order->adjustments_cents, $currency),
                'total' => $this->money((int) $order->total_cents, $currency),
                'amount_paid' => $this->money((int) $order->amount_paid_cents, $currency),
                'amount_due' => $this->money((int) $order->amount_due_cents, $currency),
                'credit_used' => $this->money((int) $order->credit_used_cents, $currency),
                'credit_generated' => $this->money((int) $order->credit_generated_cents, $currency),
            ],
            'notes' => [
                'general' => $order->general_notes,
                'kitchen' => $order->kitchen_notes,
                'pickup' => $order->pickup_notes,
                'delivery' => $order->delivery_notes,
                'recurrence' => $order->recurrence_note,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function plainTextTicket(array $ticket): string
    {
        $lines = [
            (string) $ticket['restaurant']['name'],
            (string) $ticket['title'],
            (string) $ticket['printed_label'],
            'Pedido: '.($ticket['order']['code'] ?? $ticket['order']['id']),
            'Origem: '.($ticket['order']['origin_channel'] ?? '-'),
            'Tipo: '.($ticket['order']['fulfillment_type'] ?? '-'),
            'Impresso: '.($ticket['generated_at'] ?? '-'),
            '------------------------------',
        ];

        foreach ($ticket['items'] as $item) {
            $lines[] = "{$item['quantity']}x {$item['product_name']} {$item['total_price']}";

            foreach (['item_notes', 'beneficiary_name', 'beneficiary_notes', 'substitution_notes'] as $field) {
                if (! empty($item[$field])) {
                    $lines[] = '- '.$item[$field];
                }
            }
        }

        $lines[] = '------------------------------';
        $lines[] = 'Total: '.$ticket['payment']['total'];
        $lines[] = 'Pago: '.$ticket['payment']['amount_paid'];
        $lines[] = 'Falta: '.$ticket['payment']['amount_due'];

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            return [(string) $value];
        }

        $rows = [];

        foreach ($value as $key => $item) {
            $formattedItem = is_array($item)
                ? json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $item;

            if ($formattedItem === false || $formattedItem === '') {
                continue;
            }

            $rows[] = is_string($key) ? "{$key}: {$formattedItem}" : $formattedItem;
        }

        return $rows;
    }

    private function money(int $amountCents, string $currency): string
    {
        $prefix = $currency === 'BRL' ? 'R$ ' : "{$currency} ";

        return $prefix.number_format($amountCents / 100, 2, ',', '.');
    }

    private function syncOrderPrintStatus(Order $order, PrintJob $printJob, string $status): void
    {
        $order->forceFill([
            'latest_print_job_id' => $printJob->id,
            'print_status' => $status,
            'print_error_message' => null,
        ])->save();
    }

    private function moveToReadyToPrintIfOpen(Order $order, ?User $user, PrintJob $job, bool $isReprint): void
    {
        if ($order->status === Order::STATUS_READY_TO_PRINT || in_array($order->status, Order::LOCKED_STATUSES, true)) {
            return;
        }

        $this->orders->transitionTo(
            $order,
            Order::STATUS_READY_TO_PRINT,
            $user,
            $isReprint ? 'reprint_previewed' : 'ticket_generated',
            metadata: ['print_job_id' => $job->id],
        );
    }

    private function moveToPrintedIfOpen(Order $order, ?User $user, PrintJob $job): void
    {
        if ($order->status === Order::STATUS_PRINTED || in_array($order->status, Order::LOCKED_STATUSES, true)) {
            return;
        }

        $this->orders->transitionTo(
            $order,
            Order::STATUS_PRINTED,
            $user,
            'ticket_printed',
            metadata: ['print_job_id' => $job->id],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(
        Order $order,
        ?PrintJob $job,
        ?User $user,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $message = null,
        array $metadata = [],
    ): PrintJobEvent {
        return PrintJobEvent::query()->create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'print_job_id' => $job?->id,
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'message' => $message,
            'metadata' => $metadata ?: null,
        ]);
    }
}
