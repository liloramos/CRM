<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Services\Operational\CompanyPeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class OperationalReportService
{
    public function __construct(private readonly CompanyPeriodResolver $periods) {}

    /**
     * Canonical formulas:
     * - confirmed revenue: sum of non-voided confirmed payment amounts confirmed inside the period;
     * - average ticket: confirmed revenue divided by distinct orders with a confirmed payment;
     * - average response: average seconds from each inbound message to the first following outbound message in the same conversation;
     * - print failures: print jobs whose persisted status is failed or printer_unavailable;
     * - human review: actionable human/low-confidence alerts created inside the period (history is counted only as a report event).
     */
    public function report(Company $company, ?string $from, ?string $to): array
    {
        $period = $this->periods->resolve($company, $from, $to, 7);
        $orders = Order::query()->where('company_id', $company->id)->whereBetween('created_at', [$period['from'], $period['to']])->get();
        $payments = Payment::query()->where('company_id', $company->id)->whereBetween('created_at', [$period['from'], $period['to']])->get();
        $confirmedPayments = Payment::query()
            ->where('company_id', $company->id)
            ->where('status', Payment::STATUS_CONFIRMED)
            ->whereNull('voided_at')
            ->where(function ($query) use ($period): void {
                $query->whereBetween('confirmed_at', [$period['from'], $period['to']])
                    ->orWhere(function ($legacy) use ($period): void {
                        $legacy->whereNull('confirmed_at')->whereBetween('created_at', [$period['from'], $period['to']]);
                    });
            })
            ->get();
        $printJobs = PrintJob::query()->where('company_id', $company->id)->whereBetween('created_at', [$period['from'], $period['to']])->get();
        $conversations = Conversation::query()->where('company_id', $company->id)->whereBetween('started_at', [$period['from'], $period['to']])->get();
        $reviewCount = ConversationAlert::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->whereIn('type', [ConversationAlert::TYPE_HUMAN_REQUESTED, ConversationAlert::TYPE_LOW_CONFIDENCE_AI])
            ->count();
        $responseSamples = $this->responseSamples($company, $period['from'], $period['to']);
        $confirmedRevenue = (int) $confirmedPayments->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents));
        $paidOrders = $confirmedPayments->pluck('order_id')->unique()->count();

        return [
            'period' => ['from' => $period['from_date'], 'to' => $period['to_date'], 'timezone' => $period['timezone']],
            'metrics' => [
                'averageResponseSeconds' => $responseSamples->isEmpty() ? null : (int) round($responseSamples->average()),
                'responseSampleCount' => $responseSamples->count(),
                'ordersCreated' => $orders->count(),
                'ordersCompleted' => $orders->where('status', Order::STATUS_FINISHED)->count(),
                'ordersCancelled' => $orders->where('status', Order::STATUS_CANCELLED)->count(),
                'ordersPaid' => $paidOrders,
                'averageTicket' => $paidOrders > 0 ? $this->money((int) round($confirmedRevenue / $paidOrders)) : null,
                'confirmedRevenue' => $this->money($confirmedRevenue),
                'paymentsConfirmed' => $confirmedPayments->count(),
                'paymentsPending' => $payments->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_AWAITING_PROOF, Payment::STATUS_PROOF_RECEIVED])->count(),
                'pixAwaitingReview' => $payments->where('method', Payment::METHOD_PIX)->where('status', Payment::STATUS_PROOF_RECEIVED)->count(),
                'printJobsGenerated' => $printJobs->count(),
                'printJobsPrinted' => $printJobs->whereIn('status', [PrintJob::STATUS_PRINTED, PrintJob::STATUS_MANUAL_CONFIRMED])->count(),
                'printFailures' => $printJobs->whereIn('status', [PrintJob::STATUS_FAILED, PrintJob::STATUS_PRINTER_UNAVAILABLE])->count(),
                'conversationsStarted' => $conversations->count(),
                'conversationsAutomated' => $conversations->where('automation_mode', Conversation::AUTOMATION_MODE_AUTOMATIC)->count(),
                'humanReviewEvents' => $reviewCount,
            ],
            'sufficiency' => [
                'responseTime' => $responseSamples->isNotEmpty(),
                'orders' => $orders->isNotEmpty(),
                'payments' => $payments->isNotEmpty(),
                'printing' => $printJobs->isNotEmpty(),
                'conversations' => $conversations->isNotEmpty(),
            ],
            'charts' => [
                'revenueByDay' => $this->revenueByDay($confirmedPayments, $period['from_date'], $period['to_date'], $period['timezone']),
                'ordersByStatus' => $orders->groupBy('status')->map(fn (Collection $items, string $status): array => ['label' => $status, 'value' => $items->count()])->values(),
                'paymentsByMethod' => $confirmedPayments->groupBy('method')->map(fn (Collection $items, string $method): array => ['label' => $method, 'value' => $items->count()])->values(),
                'ordersByDay' => $this->ordersByDay($orders, $period['from_date'], $period['to_date'], $period['timezone']),
            ],
            'formulas' => [
                'averageResponseSeconds' => 'Media entre mensagem recebida e primeira resposta enviada na mesma conversa.',
                'confirmedRevenue' => 'Soma de pagamentos confirmados e nao anulados no periodo.',
                'averageTicket' => 'Faturamento confirmado dividido por pedidos pagos distintos.',
                'printFailures' => 'Comandas com status failed ou printer_unavailable.',
            ],
        ];
    }

    /** @return Collection<int, int> */
    private function responseSamples(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $messages = Message::query()
            ->whereHas('conversation', fn ($query) => $query->where('company_id', $company->id))
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('conversation_id')
            ->orderBy('created_at')
            ->get();

        return $messages->groupBy('conversation_id')->flatMap(function (Collection $conversationMessages): array {
            $rows = $conversationMessages->values();
            $samples = [];

            foreach ($rows as $index => $message) {
                if (! $this->isInbound($message)) {
                    continue;
                }

                $response = $rows->slice($index + 1)->first(fn ($candidate): bool => $this->isOutbound($candidate));
                if ($response?->created_at && $message->created_at) {
                    $samples[] = max(0, $message->created_at->diffInSeconds($response->created_at));
                }
            }

            return $samples;
        })->values();
    }

    private function isInbound(Message $message): bool
    {
        return $message->direction === 'inbound' || $message->sender === 'customer';
    }

    private function isOutbound(Message $message): bool
    {
        return $message->direction === 'outbound' || in_array($message->sender, ['agent', 'attendant', 'assistant', 'bot'], true);
    }

    /** @param Collection<int, Payment> $payments */
    private function revenueByDay(Collection $payments, string $from, string $to, string $timezone): array
    {
        $amounts = $payments->groupBy(fn (Payment $payment): string => ($payment->confirmed_at ?? $payment->created_at)->setTimezone($timezone)->toDateString())
            ->map(fn (Collection $items): float => $this->money((int) $items->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents))));

        return $this->dateSeries($from, $to, fn (string $date): float => (float) ($amounts[$date] ?? 0));
    }

    /** @param Collection<int, Order> $orders */
    private function ordersByDay(Collection $orders, string $from, string $to, string $timezone): array
    {
        $counts = $orders->groupBy(fn (Order $order): string => $order->created_at->setTimezone($timezone)->toDateString())->map->count();

        return $this->dateSeries($from, $to, fn (string $date): int => (int) ($counts[$date] ?? 0));
    }

    private function dateSeries(string $from, string $to, callable $value): array
    {
        $cursor = CarbonImmutable::parse($from);
        $last = CarbonImmutable::parse($to);
        $series = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $date = $cursor->toDateString();
            $series[] = ['date' => $date, 'value' => $value($date)];
            $cursor = $cursor->addDay();
        }

        return $series;
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
