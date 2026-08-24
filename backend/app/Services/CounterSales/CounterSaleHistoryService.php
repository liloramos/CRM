<?php

namespace App\Services\CounterSales;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CounterSaleHistoryService
{
    public function __construct(private readonly StructuredProductConfigurationService $configuration) {}

    /**
     * @param  array{date_from?: string, date_to?: string, payment_method?: string, status?: string}  $filters
     * @return array<string, mixed>
     */
    public function history(Company $company, array $filters): array
    {
        [$from, $to] = $this->dateRange($company, $filters);
        $orders = $this->baseQuery($company)
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->when($filters['payment_method'] ?? null, fn (Builder $query, string $method): Builder => $query->where('payment_method', $method))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $this->orderStatus($status)))
            ->latest('created_at')
            ->get();

        $completed = $orders->filter(fn (Order $order): bool => $this->isCompleted($order));
        $cancelled = $orders->where('status', Order::STATUS_CANCELLED);

        return [
            'filters' => [
                'dateFrom' => $from->toDateString(),
                'dateTo' => $to->toDateString(),
                'paymentMethod' => $filters['payment_method'] ?? null,
                'status' => $filters['status'] ?? null,
                'timezone' => $this->timezone($company),
            ],
            'summary' => [
                'dateLabel' => $this->dateLabel($from, $to),
                'totalSoldCents' => (int) $completed->sum('amount_paid_cents'),
                'completedSalesCount' => $completed->count(),
                'totalCancelledCents' => (int) $cancelled->sum('total_cents'),
                'paymentTotals' => $this->paymentTotals($completed),
            ],
            'topProducts' => $this->topProducts($completed),
            'sales' => $orders->map(fn (Order $order): array => $this->saleSummary($order, $company))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Company $company, Order $order): array
    {
        if ((int) $order->company_id !== (int) $company->id || ! $this->isCounterSale($order)) {
            throw new DomainException('Esta venda de balcão não pertence ao restaurante atual.');
        }

        $order->loadMissing(['items.product', 'payments', 'statusHistories.user']);
        $timezone = $this->timezone($company);

        return [
            ...$this->saleSummary($order, $company),
            'originLabel' => 'Venda de balcão',
            'payment' => $this->paymentDetail($order, $timezone),
            'items' => $order->items
                ->sortBy('sort_order')
                ->map(fn ($item): array => [
                    'id' => (string) $item->id,
                    'productName' => $item->product_name,
                    'productImageUrl' => $item->product ? $this->configuration->productImageUrl($item->product) : null,
                    'quantity' => (int) $item->quantity,
                    'unitPriceCents' => (int) $item->unit_price_cents,
                    'subtotalCents' => (int) $item->total_price_cents,
                ])
                ->values()
                ->all(),
            'history' => $order->statusHistories
                ->sortByDesc('created_at')
                ->map(fn ($history): array => [
                    'id' => (string) $history->id,
                    'title' => $this->statusLabel((string) $history->to_status),
                    'description' => $history->notes ?: 'Atualização operacional registrada.',
                    'actorName' => $history->user?->name,
                    'timeLabel' => $history->created_at?->setTimezone($timezone)->format('d/m H:i') ?? '',
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return Builder<Order> */
    private function baseQuery(Company $company): Builder
    {
        return Order::query()
            ->with(['items.product', 'payments', 'statusHistories.user'])
            ->where('company_id', $company->id)
            ->where('origin_channel', Order::CHANNEL_COUNTER)
            ->where('fulfillment_type', Order::FULFILLMENT_COUNTER);
    }

    /** @param array<string, string> $filters @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dateRange(Company $company, array $filters): array
    {
        $timezone = $this->timezone($company);
        $from = isset($filters['date_from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();
        $to = isset($filters['date_to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone)
            : ($filters['date_from'] ?? null ? $from : CarbonImmutable::now($timezone)->startOfDay());

        if ($to->lessThan($from)) {
            throw new DomainException('A data final precisa ser igual ou posterior à data inicial.');
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    /** @return array{cash: int, pix: int, card: int} */
    private function paymentTotals(Collection $orders): array
    {
        return [
            'cash' => (int) $orders->where('payment_method', Payment::METHOD_CASH)->sum('amount_paid_cents'),
            'pix' => (int) $orders->where('payment_method', Payment::METHOD_PIX)->sum('amount_paid_cents'),
            'card' => (int) $orders
                ->whereIn('payment_method', [Payment::METHOD_DEBIT_CARD, Payment::METHOD_CREDIT_CARD])
                ->sum('amount_paid_cents'),
        ];
    }

    /** @return list<array{productName: string, quantity: int, totalCents: int}> */
    private function topProducts(Collection $orders): array
    {
        return $orders
            ->flatMap(fn (Order $order) => $order->items)
            ->groupBy('product_name')
            ->map(fn (Collection $items, string $productName): array => [
                'productName' => $productName,
                'quantity' => (int) $items->sum('quantity'),
                'totalCents' => (int) $items->sum('total_price_cents'),
            ])
            ->sort(function (array $left, array $right): int {
                return $right['quantity'] <=> $left['quantity']
                    ?: $right['totalCents'] <=> $left['totalCents'];
            })
            ->take(5)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function saleSummary(Order $order, Company $company): array
    {
        $timezone = $this->timezone($company);
        $isCancelled = $order->status === Order::STATUS_CANCELLED;

        return [
            'id' => (string) $order->id,
            'code' => $order->code,
            'timeLabel' => $order->created_at?->setTimezone($timezone)->format('H:i') ?? '',
            'dateLabel' => $order->created_at?->setTimezone($timezone)->format('d/m/Y') ?? '',
            'itemsQuantity' => (int) $order->items->sum('quantity'),
            'totalCents' => (int) $order->total_cents,
            'paymentMethod' => $order->payment_method,
            'paymentMethodLabel' => $this->paymentMethodLabel((string) $order->payment_method),
            'status' => $isCancelled ? 'cancelled' : 'completed',
            'statusLabel' => $isCancelled ? 'Cancelada' : 'Concluída',
            'isCancellable' => ! $isCancelled && $this->isCompleted($order),
        ];
    }

    /** @return array<string, mixed> */
    private function paymentDetail(Order $order, string $timezone): array
    {
        $payment = $order->payments->sortByDesc('id')->first();

        return [
            'methodLabel' => $this->paymentMethodLabel((string) ($payment?->method ?: $order->payment_method)),
            'statusLabel' => $payment?->voided_at ? 'Anulado' : 'Confirmado',
            'amountCents' => (int) ($payment?->confirmed_amount_cents ?: $order->amount_paid_cents),
            'confirmedAtLabel' => $payment?->confirmed_at?->setTimezone($timezone)->format('d/m H:i')
                ?? $order->payment_confirmed_at?->setTimezone($timezone)->format('d/m H:i'),
            'voidReason' => $payment?->void_reason,
        ];
    }

    private function isCounterSale(Order $order): bool
    {
        return $order->origin_channel === Order::CHANNEL_COUNTER
            && $order->fulfillment_type === Order::FULFILLMENT_COUNTER;
    }

    private function isCompleted(Order $order): bool
    {
        return $order->status === Order::STATUS_FINISHED
            && $order->payment_status === Payment::ORDER_STATUS_PAID;
    }

    private function orderStatus(string $status): string
    {
        return $status === 'cancelled' ? Order::STATUS_CANCELLED : Order::STATUS_FINISHED;
    }

    private function timezone(Company $company): string
    {
        return $company->setting?->timezone ?: config('app.timezone');
    }

    private function dateLabel(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->isSameDay($to) ? $from->format('d/m/Y') : $from->format('d/m/Y').' a '.$to->format('d/m/Y');
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            Payment::METHOD_CASH => 'Dinheiro',
            Payment::METHOD_PIX => 'Pix',
            Payment::METHOD_DEBIT_CARD, Payment::METHOD_CREDIT_CARD => 'Cartão',
            default => 'A confirmar',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Order::STATUS_FINISHED => 'Venda concluída',
            Order::STATUS_CANCELLED => 'Venda cancelada',
            Order::STATUS_PAYMENT_CONFIRMED => 'Pagamento confirmado',
            default => 'Atualização da venda',
        };
    }
}
