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
    private const COUNTER_DRAFT_RULE_CODES = [
        'self_service_counter',
        'counter_weight_standard',
        'counter_weight_meat_only',
    ];

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
            if (! in_array($targetAudience, [
                ReceiptTemplate::TARGET_KITCHEN,
                ReceiptTemplate::TARGET_CASHIER,
                ReceiptTemplate::TARGET_DELIVERY,
            ], true)) {
                throw new DomainException('O destino de impressão informado não é válido.');
            }

            if ($this->isCounterSaleDraft($order) && ! $this->isOperationalCounterDraft($order, $targetAudience)) {
                throw new DomainException('Somente uma comanda de balcão elegível pode ser impressa antes da finalização.');
            }

            $template = $this->resolveTemplate($order, $attributes['receipt_template_id'] ?? null, $targetAudience);
            $printer = $this->resolvePrinter($order, $attributes['printer_setting_id'] ?? null);
            $copyNumber = ((int) $order->printJobs()->max('copy_number')) + 1;
            $previousPrintJobId = $order->latest_print_job_id;
            $requestedParentPrintJobId = $attributes['parent_print_job_id'] ?? null;
            $isReprint = (bool) ($attributes['is_reprint'] ?? ($requestedParentPrintJobId !== null || $copyNumber > 1));
            $parentPrintJobId = $requestedParentPrintJobId ?? ($isReprint ? $previousPrintJobId : null);
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
                'print_status' => $this->hasConfirmedPrint($order)
                    ? $order->print_status
                    : Order::PRINT_STATUS_PREVIEWED,
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

            if ($printJob->status === PrintJob::STATUS_PRINTING) {
                return $printJob;
            }

            if (in_array($printJob->status, [PrintJob::STATUS_PRINTED, PrintJob::STATUS_MANUAL_CONFIRMED], true)) {
                return $printJob;
            }

            if (! in_array($printJob->status, [PrintJob::STATUS_PREVIEWED, PrintJob::STATUS_QUEUED, PrintJob::STATUS_REPRINT_REQUESTED], true)) {
                throw new DomainException('A comanda precisa estar pronta para impressão antes de iniciar o envio à impressora.');
            }

            $fromStatus = $printJob->status;

            $printJob->forceFill([
                'status' => PrintJob::STATUS_PRINTING,
                'printing_started_at' => now(),
                'error_message' => null,
            ])->save();

            if ($this->hasConfirmedPrint($order)) {
                $order->forceFill([
                    'latest_print_job_id' => $printJob->id,
                    'print_error_message' => null,
                ])->save();
            } else {
                $this->syncOrderPrintStatus($order, $printJob, Order::PRINT_STATUS_PRINTING);
            }
            $this->recordEvent($order, $printJob, $user, PrintJobEvent::EVENT_PRINT_STARTED, $fromStatus, PrintJob::STATUS_PRINTING);

            return $printJob->refresh();
        });
    }

    public function markPrinted(PrintJob $printJob, ?User $user = null): PrintJob
    {
        return DB::transaction(function () use ($printJob, $user): PrintJob {
            $printJob = $this->lockPrintJob($printJob);
            $order = $this->lockOrder($printJob->order()->firstOrFail());

            if ($printJob->status === PrintJob::STATUS_PRINTED) {
                $this->advanceAfterPrintConfirmation($order, $user, $printJob);

                return $printJob;
            }

            if ($printJob->status !== PrintJob::STATUS_PRINTING) {
                throw new DomainException('Inicie a impressão da comanda antes de confirmar a impressão física.');
            }

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
            $this->advanceAfterPrintConfirmation($order->refresh(), $user, $printJob);

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
            if ($this->isCounterSaleDraft($order)) {
                throw new DomainException('Comandas abertas devem usar a ficha operacional de balcão.');
            }

            if ($this->hasConfirmedPrint($order)) {
                $job = $order->latestPrintJob()->first();

                if ($job instanceof PrintJob) {
                    $this->advanceAfterPrintConfirmation($order, $user, $job);
                }

                return $order->refresh();
            }

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

            $this->advanceAfterPrintConfirmation($order->refresh(), $user, $job);

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
            'items.product',
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
        $isOperationalCounterDraft = $this->isOperationalCounterDraft($order, $targetAudience);
        $isNonFiscalReceipt = ! $isOperationalCounterDraft
            && $targetAudience === ReceiptTemplate::TARGET_CASHIER
            && $order->origin_channel === Order::CHANNEL_COUNTER
            && $order->fulfillment_type === Order::FULFILLMENT_COUNTER;

        return [
            'restaurant' => [
                'name' => $restaurantProfile?->display_name ?? $company?->name ?? 'Restaurante',
                'phone' => $restaurantProfile?->contact_phone,
                'logo_src' => $this->ticketLogoDataUri(),
            ],
            'title' => $isOperationalCounterDraft
                ? 'COMANDA DE BALCÃO'
                : ($isNonFiscalReceipt ? 'COMPROVANTE NÃO FISCAL' : 'COMANDA DE PEDIDO'),
            'is_operational_counter_draft' => $isOperationalCounterDraft,
            'is_non_fiscal_receipt' => $isNonFiscalReceipt,
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
                'paper_width_mm' => $printer?->paper_width_mm ?? 80,
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
                'payer_name' => $this->orderCustomerName($order),
                'payer_phone' => $this->orderCustomerPhone($order),
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
                            'price_delta_cents' => (int) $option->price_delta_cents,
                            'total_price_cents' => (int) $option->total_price_cents,
                            'price_delta' => $this->money((int) $option->price_delta_cents, $currency),
                            'total_price' => $this->money((int) $option->total_price_cents, $currency),
                            'metadata' => $option->metadata ?? [],
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
            'print' => $this->humanTicketData($order, $currency, $isNonFiscalReceipt, $isOperationalCounterDraft),
        ];
    }

    /** @return array<string, mixed> */
    private function humanTicketData(
        Order $order,
        string $currency,
        bool $isNonFiscalReceipt,
        bool $isOperationalCounterDraft,
    ): array {
        $items = $order->items->sortBy('sort_order')->values()->map(function ($item) use ($currency): array {
            $ingredients = $this->humanList($item->selected_components);
            $additions = [];

            foreach ($item->options as $option) {
                $amount = (int) $option->total_price_cents;
                $isAddition = $amount > 0 || str_contains(mb_strtolower((string) $option->name), 'adicional');
                if ($isAddition && $amount === 0 && $item->product !== null) {
                    $amount = max(0, (int) $item->total_price_cents - (int) $item->product->base_price_cents);
                }
                $line = [
                    'quantity' => (int) $option->quantity,
                    'name' => $option->name,
                    'amount_cents' => $amount,
                    'amount' => $this->money($amount, $currency),
                ];

                if ($isAddition) {
                    $additions[] = $line;
                } else {
                    $ingredients[] = $option->name;
                }
            }

            return [
                'quantity' => (int) $item->quantity,
                'name' => $item->product_name,
                'amount_cents' => (int) $item->total_price_cents - (int) $item->options_total_cents,
                'amount' => $this->money((int) $item->total_price_cents - (int) $item->options_total_cents, $currency),
                'total_cents' => (int) $item->total_price_cents,
                'total' => $this->money((int) $item->total_price_cents, $currency),
                'weight_grams' => $item->weight_grams !== null ? (int) $item->weight_grams : null,
                'weight' => $item->weight_grams !== null ? $this->weightInKilograms((int) $item->weight_grams) : null,
                'price_per_kg_cents' => $item->price_per_kg_cents !== null ? (int) $item->price_per_kg_cents : null,
                'price_per_kg' => $item->price_per_kg_cents !== null
                    ? $this->money((int) $item->price_per_kg_cents, $currency).'/kg'
                    : null,
                'ingredients' => array_values(array_unique(array_filter($ingredients))),
                'additions' => $additions,
                'notes' => array_values(array_filter([
                    $item->item_notes,
                    $item->beneficiary_name ? 'Para: '.$item->beneficiary_name : null,
                    $item->beneficiary_notes,
                    $item->substitution_notes,
                ])),
                'removed' => $this->humanList($item->removed_ingredients),
            ];
        })->all();

        $additionsCents = collect($items)->sum(fn (array $item): int => collect($item['additions'])->sum('amount_cents'));
        $address = $order->fulfillment_type === Order::FULFILLMENT_DELIVERY
            ? $this->humanDeliveryAddress($order)
            : [];
        $notes = array_values(array_filter([
            $order->general_notes,
            $order->kitchen_notes,
            $order->fulfillment_type === Order::FULFILLMENT_DELIVERY ? $order->delivery_notes : null,
            $order->fulfillment_type === Order::FULFILLMENT_PICKUP ? $order->pickup_notes : null,
        ]));

        return [
            'is_operational_counter_draft' => $isOperationalCounterDraft,
            'is_non_fiscal_receipt' => $isNonFiscalReceipt,
            'document_label' => $isOperationalCounterDraft
                ? 'COMANDA DE BALCÃO'
                : ($isNonFiscalReceipt ? 'COMPROVANTE NÃO FISCAL' : null),
            'fiscal_disclaimer' => $isNonFiscalReceipt ? 'Este documento não é um documento fiscal.' : null,
            'fulfillment_label' => match ($order->fulfillment_type) {
                Order::FULFILLMENT_DELIVERY => 'Entrega',
                Order::FULFILLMENT_COUNTER => 'Balcão / Restaurante',
                default => 'Retirada',
            },
            'customer_name' => $this->ticketCustomerName($order),
            'customer_phone' => $this->orderCustomerPhone($order),
            'address' => $address,
            'items' => $items,
            'notes' => $notes,
            'items_cents' => (int) $order->subtotal_cents - $additionsCents,
            'items_total' => $this->money((int) $order->subtotal_cents - $additionsCents, $currency),
            'additions_cents' => $additionsCents,
            'additions_total' => $this->money($additionsCents, $currency),
            'delivery_fee_cents' => (int) $order->delivery_fee_cents,
            'delivery_fee' => $this->money((int) $order->delivery_fee_cents, $currency),
            'adjustments_cents' => (int) $order->adjustments_cents,
            'adjustments' => $this->money((int) $order->adjustments_cents, $currency),
            'total' => $this->money((int) $order->total_cents, $currency),
            'payment_method' => $this->humanPaymentMethod($order->payment_method),
            'payment_amount_cents' => (int) $order->amount_paid_cents,
            'payment_amount' => $this->money((int) $order->amount_paid_cents, $currency),
            'order_date' => $order->order_date?->format('d/m/Y') ?? $order->created_at?->format('d/m/Y'),
            'order_time' => $order->created_at?->format('H:i'),
            'operational_draft' => $this->operationalDraftData($order, $currency),
        ];
    }

    /** @return array<string, mixed>|null */
    private function operationalDraftData(Order $order, string $currency): ?array
    {
        $item = $order->items->sortBy('sort_order')->first();
        if ($item === null || ! in_array($item->menu_rule_code, self::COUNTER_DRAFT_RULE_CODES, true)) {
            return null;
        }

        $isWeight = in_array($item->menu_rule_code, ['counter_weight_standard', 'counter_weight_meat_only'], true);

        return [
            'modality_label' => $item->product_name,
            'is_weight' => $isWeight,
            'price_per_kg' => $isWeight && $item->price_per_kg_cents !== null
                ? $this->money((int) $item->price_per_kg_cents, $currency).'/kg'
                : null,
            'base_price' => ! $isWeight ? $this->money((int) $item->unit_price_cents, $currency) : null,
            'selected_components' => $this->humanList($item->selected_components),
            'has_extra_beef' => $item->options->contains('group_code', 'bife_adicional'),
            'notes' => array_values(array_filter([$order->general_notes, $item->item_notes])),
        ];
    }

    private function weightInKilograms(int $grams): string
    {
        $kilograms = intdiv($grams, 1000);
        $remainingGrams = str_pad((string) ($grams % 1000), 3, '0', STR_PAD_LEFT);

        return number_format($kilograms, 0, ',', '.').','.$remainingGrams.' kg';
    }

    private function ticketLogoDataUri(): ?string
    {
        $logoPath = public_path('images/logo-comanda.png');

        if (! is_file($logoPath)) {
            return null;
        }

        $contents = file_get_contents($logoPath);

        return $contents === false ? null : 'data:image/png;base64,'.base64_encode($contents);
    }

    /** @return list<string> */
    private function humanDeliveryAddress(Order $order): array
    {
        $address = (array) ($order->delivery_address_snapshot ?? []);
        if ($address === [] && $order->deliveryAddress !== null) {
            $address = $order->deliveryAddress->only(['street', 'number', 'complement', 'neighborhood', 'city', 'state', 'reference']);
        }

        return array_values(array_filter([
            trim(implode(', ', array_filter([$address['street'] ?? null, $address['number'] ?? null]))),
            $address['complement'] ?? null,
            $address['neighborhood'] ?? null,
            trim(implode('/', array_filter([$address['city'] ?? null, $address['state'] ?? null]))),
            ! empty($address['reference']) ? 'Referência: '.$address['reference'] : null,
        ]));
    }

    /** @return list<string> */
    private function humanList(mixed $value): array
    {
        if (! is_array($value)) {
            return $value ? [(string) $value] : [];
        }

        return collect($value)->filter(fn (mixed $line): bool => is_string($line))->map(fn (string $line): string => trim($line))->filter()->values()->all();
    }

    private function ticketCustomerName(Order $order): ?string
    {
        $name = trim((string) ($order->customer_name_snapshot ?: $order->payerCustomer?->name));

        return $name !== '' ? $name : null;
    }

    private function humanPaymentMethod(?string $method): ?string
    {
        return match ($method) {
            'pix' => 'Pix', 'cash' => 'Dinheiro', 'debit_card' => 'Cartão de débito',
            'credit_card' => 'Cartão de crédito', default => $method ? 'A confirmar' : null,
        };
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function plainTextTicket(array $ticket): string
    {
        if ($ticket['is_operational_counter_draft']) {
            return $this->plainTextOperationalCounterDraft($ticket);
        }

        if ($ticket['is_non_fiscal_receipt']) {
            return $this->plainTextCounterReceipt($ticket);
        }

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

            foreach ($item['options'] as $option) {
                $line = "- {$option['quantity']}x {$option['name']}";

                if (($option['total_price_cents'] ?? 0) > 0) {
                    $line .= ' '.$option['total_price'];
                } elseif (($option['price_delta_cents'] ?? 0) > 0) {
                    $line .= ' '.$option['price_delta'];
                }

                $lines[] = $line;
            }

            if ($item['removed_ingredients'] !== []) {
                $lines[] = 'RETIRAR:';
                foreach ($item['removed_ingredients'] as $detail) {
                    $lines[] = '- '.$detail;
                }
            }

            if (empty($item['options'])) {
                foreach ($item['selected_components'] as $detail) {
                    $lines[] = '- '.$detail;
                }
            }
        }

        $lines[] = '------------------------------';
        $lines[] = 'Total: '.$ticket['payment']['total'];
        $lines[] = 'Pago: '.$ticket['payment']['amount_paid'];
        $lines[] = 'Falta: '.$ticket['payment']['amount_due'];

        return implode(PHP_EOL, $lines);
    }

    /** @param array<string, mixed> $ticket */
    private function plainTextCounterReceipt(array $ticket): string
    {
        $print = $ticket['print'];
        $lines = [
            (string) $ticket['restaurant']['name'],
            (string) $print['document_label'],
            'Venda: '.($ticket['order']['code'] ?? $ticket['order']['id']),
            'Data: '.$print['order_date'].($print['order_time'] ? ' '.$print['order_time'] : ''),
            '------------------------------',
        ];

        foreach ($print['items'] as $item) {
            $lines[] = (string) $item['name'];
            if ($item['weight'] !== null) {
                $lines[] = 'Peso: '.$item['weight'];
                $lines[] = 'Preço por kg: '.$item['price_per_kg'];
                $lines[] = 'Subtotal: '.$item['amount'];
            } else {
                $lines[] = $item['quantity'].' x '.$item['amount'];
            }

            if ($item['additions'] !== []) {
                $lines[] = 'Adicional:';
                foreach ($item['additions'] as $addition) {
                    $lines[] = '- '.$addition['name'].' '.$addition['amount'];
                }
                $lines[] = 'Total do item: '.$item['total'];
            }
        }

        $lines[] = '------------------------------';
        $lines[] = 'TOTAL: '.$print['total'];
        if ($print['payment_method']) {
            $lines[] = 'Pagamento: '.$print['payment_method'].' '.$print['payment_amount'];
        }
        $lines[] = '------------------------------';
        $lines[] = (string) $print['fiscal_disclaimer'];

        return implode(PHP_EOL, $lines);
    }

    /** @param array<string, mixed> $ticket */
    private function plainTextOperationalCounterDraft(array $ticket): string
    {
        $draft = $ticket['print']['operational_draft'];
        $lines = [
            (string) $ticket['restaurant']['name'],
            'COMANDA DE BALCÃO',
            'Comanda: '.($ticket['order']['code'] ?? $ticket['order']['id']),
            'Abertura: '.($ticket['order']['created_at'] ?? '-'),
            'Modalidade: '.$draft['modality_label'],
        ];

        if ($draft['price_per_kg']) {
            $lines[] = 'Tarifa: '.$draft['price_per_kg'];
        } elseif ($draft['base_price']) {
            $lines[] = 'Base: '.$draft['base_price'];
        }

        $lines[] = '------------------------------';
        $lines[] = 'ANOTAÇÕES DA CHAPA';
        if ($draft['is_weight']) {
            $lines[] = 'Peso: ______________ g';
        }
        $lines[] = 'Bife adicional: '.($draft['has_extra_beef'] ? '[X] Sim  [ ] Não' : '[ ] Sim  [ ] Não');
        $lines[] = 'Quantidade de bife: ________';
        $lines[] = 'Outros adicionais: __________________';
        $lines[] = '____________________________________';
        $lines[] = 'Observações: ________________________';
        $lines[] = '____________________________________';

        return implode(PHP_EOL, $lines);
    }

    private function orderCustomerName(Order $order): string
    {
        $snapshot = trim((string) $order->customer_name_snapshot);

        if ($snapshot !== '') {
            return $snapshot;
        }

        return $order->payerCustomer?->name ?: 'Cliente avulso';
    }

    private function orderCustomerPhone(Order $order): ?string
    {
        $snapshot = trim((string) $order->customer_phone_snapshot);

        if ($snapshot !== '') {
            return $snapshot;
        }

        return $order->payerCustomer?->phone;
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
        if ($this->isOperationalCounterDraft($order, (string) $job->target_audience)) {
            return;
        }

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

    private function advanceAfterPrintConfirmation(Order $order, ?User $user, PrintJob $job): void
    {
        $order->loadMissing('items.product');
        if ($this->isOperationalCounterDraft($order, (string) $job->target_audience)) {
            return;
        }

        if (in_array($order->status, [
            Order::STATUS_IN_PREPARATION,
            Order::STATUS_READY_FOR_PICKUP,
            Order::STATUS_OUT_FOR_DELIVERY,
            Order::STATUS_FINISHED,
            Order::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        if ($order->status !== Order::STATUS_PRINTED) {
            $order = $this->orders->transitionTo(
                $order,
                Order::STATUS_PRINTED,
                $user,
                'ticket_printed',
                'Impressão da comanda confirmada pela equipe.',
                ['print_job_id' => $job->id],
            );
        }

        $this->orders->transitionTo(
            $order,
            Order::STATUS_IN_PREPARATION,
            $user,
            'preparation_started_after_print_confirmation',
            'Pedido liberado para preparo após a confirmação da impressão.',
            ['print_job_id' => $job->id],
        );
    }

    private function hasConfirmedPrint(Order $order): bool
    {
        return $order->printed_at !== null
            || in_array($order->print_status, [
                Order::PRINT_STATUS_PRINTED,
                Order::PRINT_STATUS_MANUAL_CONFIRMED,
            ], true);
    }

    private function isOperationalCounterDraft(Order $order, string $targetAudience): bool
    {
        if (
            $targetAudience !== ReceiptTemplate::TARGET_CASHIER
            || ! $this->isCounterSaleDraft($order)
        ) {
            return false;
        }

        $order->loadMissing('items.product');
        $item = $order->items->sortBy('sort_order')->first();

        return $item !== null && in_array($item->menu_rule_code, self::COUNTER_DRAFT_RULE_CODES, true);
    }

    private function isCounterSaleDraft(Order $order): bool
    {
        return $order->status === Order::STATUS_DRAFT
            && $order->origin_channel === Order::CHANNEL_COUNTER
            && $order->fulfillment_type === Order::FULFILLMENT_COUNTER
            && $order->statusHistories()->where('reason', 'counter_sale_draft_opened')->exists();
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
