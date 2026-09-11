<?php

namespace App\Services\Financial;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Operational\CompanyPeriodResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FinancialOverviewService
{
    public function __construct(private readonly CompanyPeriodResolver $periods) {}

    /** @param array{from?: string|null, to?: string|null, method?: string|null, status?: string|null, search?: string|null} $filters */
    public function overview(Company $company, array $filters): array
    {
        $period = $this->periods->resolve($company, $filters['from'] ?? null, $filters['to'] ?? null, 1);
        $query = Payment::query()
            ->with(['order.payerCustomer', 'order.payments.createdBy', 'order.payments.confirmedBy', 'order.payments.rejectedBy', 'order.payments.voidedBy', 'customer', 'createdBy', 'confirmedBy', 'rejectedBy', 'voidedBy'])
            ->where('company_id', $company->id)
            ->where(function (Builder $dates) use ($period): void {
                $dates->whereBetween('created_at', [$period['from'], $period['to']])
                    ->orWhereBetween('confirmed_at', [$period['from'], $period['to']])
                    ->orWhereBetween('rejected_at', [$period['from'], $period['to']])
                    ->orWhereBetween('voided_at', [$period['from'], $period['to']]);
            });

        $this->applyFilters($query, $filters);
        $payments = $query->latest('created_at')->limit(250)->get();
        $confirmed = $payments->filter(fn (Payment $payment): bool => $payment->status === Payment::STATUS_CONFIRMED
            && $payment->voided_at === null
            && $this->insidePeriod($payment->confirmed_at ?? $payment->created_at, $period));
        $pending = $payments->filter(fn (Payment $payment): bool => $this->insidePeriod($payment->created_at, $period) && in_array($payment->status, [
            Payment::STATUS_PENDING,
            Payment::STATUS_AWAITING_PROOF,
            Payment::STATUS_PROOF_RECEIVED,
        ], true));
        $confirmedRevenue = (int) $confirmed->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents));
        $confirmedOrderPayments = $confirmed->filter(fn (Payment $payment): bool => $payment->order_id !== null);
        $paidOrders = $confirmedOrderPayments->pluck('order_id')->unique()->count();
        $confirmedOrderRevenue = (int) $confirmedOrderPayments->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents));
        $movements = $this->movements($payments, $period);

        return [
            'period' => [
                'from' => $period['from_date'],
                'to' => $period['to_date'],
                'timezone' => $period['timezone'],
            ],
            'summary' => [
                'confirmedRevenue' => $this->money($confirmedRevenue),
                'receivedAmount' => $this->money($confirmedRevenue),
                'pendingAmount' => $this->money((int) $pending->sum('amount_cents')),
                'creditBalance' => $this->money((int) Customer::query()->where('company_id', $company->id)->sum('credit_balance_cents')),
                'voidedAmount' => $this->money((int) $payments->filter(fn (Payment $payment): bool => $payment->voided_at !== null && $this->insidePeriod($payment->voided_at, $period))->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents))),
                'voidedCount' => $payments->filter(fn (Payment $payment): bool => $payment->voided_at !== null && $this->insidePeriod($payment->voided_at, $period))->count(),
                'averageTicket' => $paidOrders > 0 ? $this->money((int) round($confirmedOrderRevenue / $paidOrders)) : null,
                'paidOrders' => $paidOrders,
                'movementCount' => count($movements),
            ],
            'movements' => $movements,
            'methods' => $this->methods($confirmed),
        ];
    }

    /** @param Builder<Payment> $query @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $method = (string) ($filters['method'] ?? '');
        if ($method !== '' && in_array($method, Payment::METHODS, true)) {
            $query->where('method', $method);
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'concluded') {
            $query->where('status', Payment::STATUS_CONFIRMED)->whereNull('voided_at');
        } elseif ($status === 'pending_group') {
            $query->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_AWAITING_PROOF, Payment::STATUS_PROOF_RECEIVED]);
        } elseif ($status === 'cancelled_group') {
            $query->where(function (Builder $cancelled): void {
                $cancelled->whereNotNull('voided_at')->orWhereIn('status', [Payment::STATUS_CANCELLED, Payment::STATUS_REJECTED]);
            });
        } elseif ($status === 'voided') {
            $query->whereNotNull('voided_at');
        } elseif ($status !== '' && in_array($status, Payment::STATUSES, true)) {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $nested) use ($needle): void {
                $nested->whereHas('order', fn (Builder $orders) => $orders->whereRaw('LOWER(code) LIKE ?', [$needle]))
                    ->orWhereHas('customer', fn (Builder $customers) => $customers->whereRaw('LOWER(name) LIKE ?', [$needle]));
            });
        }
    }

    /** @param Collection<int, Payment> $payments @param array<string, mixed> $period */
    private function movements(Collection $payments, array $period): array
    {
        $orderMovements = $payments->filter(fn (Payment $payment): bool => $payment->order_id !== null)
            ->groupBy('order_id')
            ->map(fn (Collection $orderPayments): array => $this->saleMovement($orderPayments, $period['timezone']))
            ->values();
        $independentMovements = $payments->filter(fn (Payment $payment): bool => $payment->order_id === null)
            ->flatMap(function (Payment $payment) use ($period): array {
                $events = [];

                if ($this->insidePeriod($payment->confirmed_at, $period)) {
                    $events[] = $this->movement($payment, $period['timezone'], 'payment', 'confirmed', $payment->confirmed_at);
                }
                if ($this->insidePeriod($payment->voided_at, $period)) {
                    $events[] = $this->movement($payment, $period['timezone'], 'void', 'voided', $payment->voided_at);
                } elseif ($this->insidePeriod($payment->rejected_at, $period)) {
                    $events[] = $this->movement($payment, $period['timezone'], 'payment', 'rejected', $payment->rejected_at);
                } elseif ($events === [] && $this->insidePeriod($payment->created_at, $period)) {
                    $events[] = $this->movement($payment, $period['timezone']);
                }

                return $events;
            });

        return $orderMovements->concat($independentMovements)->sortByDesc('occurredAt')->values()->all();
    }

    /** @param Collection<int, Payment> $periodPayments */
    private function saleMovement(Collection $periodPayments, string $timezone): array
    {
        /** @var Payment $representative */
        $representative = $periodPayments->sortByDesc(fn (Payment $payment): int => (int) optional($payment->confirmed_at ?? $payment->voided_at ?? $payment->created_at)->getTimestamp())->first();
        $order = $representative->order;
        $payments = $order?->payments ?? $periodPayments;
        $confirmed = $payments->filter(fn (Payment $payment): bool => $payment->status === Payment::STATUS_CONFIRMED && $payment->voided_at === null);
        $activePayments = $confirmed->isNotEmpty() ? $confirmed : $payments;
        $latest = $activePayments->sortByDesc(fn (Payment $payment): int => (int) optional($payment->confirmed_at ?? $payment->voided_at ?? $payment->created_at)->getTimestamp())->first() ?? $representative;
        $operator = $latest->voidedBy ?? $latest->confirmedBy ?? $latest->rejectedBy ?? $latest->createdBy;
        $methods = $activePayments->pluck('method')->filter()->unique()->values();
        $customer = $order?->payerCustomer ?? $representative->customer;
        $received = (int) $confirmed->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents));
        $voided = $payments->filter(fn (Payment $payment): bool => $payment->voided_at !== null);
        $total = (int) ($order?->total_cents ?? $latest->amount_cents);
        $consolidatedStatus = $this->consolidatedStatus($total, $received, $voided->isNotEmpty());

        return [
            'id' => 'order-'.($order?->id ?? $representative->order_id),
            'occurredAt' => ($representative->confirmed_at ?? $representative->voided_at ?? $representative->created_at)?->setTimezone($timezone)->toIso8601String(),
            'origin' => $order?->origin_channel ?: 'manual',
            'orderId' => (string) ($order?->id ?? $representative->order_id),
            'orderCode' => $order?->code,
            'customerName' => $customer?->name ?? $order?->customer_name_snapshot ?? 'Cliente nao identificado',
            'type' => $consolidatedStatus === 'voided' ? 'void' : 'payment',
            'method' => $methods->count() === 1 ? (string) $methods->first() : Payment::METHOD_MIXED,
            'status' => $consolidatedStatus,
            'amount' => $this->money($received),
            'totalAmount' => $this->money($total),
            'operatorName' => $operator?->name,
            'notes' => $latest->void_reason ?: $latest->rejection_reason ?: $latest->notes,
            'canVoid' => $confirmed->isNotEmpty(),
            'paymentCount' => $payments->count(),
            'details' => [
                'items' => $this->money((int) ($order?->subtotal_cents ?? 0)),
                'deliveryFee' => $this->money((int) ($order?->delivery_fee_cents ?? 0)),
                'adjustments' => $this->money((int) ($order?->adjustments_cents ?? 0)),
                'creditUsed' => $this->money((int) ($order?->credit_used_cents ?? 0)),
                'total' => $this->money($total),
                'received' => $this->money($received),
                'payments' => $payments->sortBy('created_at')->values()->map(fn (Payment $payment): array => [
                    'id' => (string) $payment->id,
                    'method' => $payment->method,
                    'status' => $payment->voided_at !== null ? 'voided' : $payment->status,
                    'amount' => $this->money((int) ($payment->confirmed_amount_cents ?: $payment->amount_cents)),
                    'occurredAt' => ($payment->confirmed_at ?? $payment->voided_at ?? $payment->created_at)?->setTimezone($timezone)->toIso8601String(),
                    'operatorName' => ($payment->voidedBy ?? $payment->confirmedBy ?? $payment->rejectedBy ?? $payment->createdBy)?->name,
                    'canVoid' => $payment->status === Payment::STATUS_CONFIRMED && $payment->voided_at === null,
                ])->all(),
            ],
        ];
    }

    private function consolidatedStatus(int $total, int $received, bool $hasVoidedPayment): string
    {
        if ($received <= 0) {
            return $hasVoidedPayment ? 'voided' : 'pending';
        }

        if ($received < $total) {
            return $hasVoidedPayment ? 'partially_voided' : 'partial';
        }

        return 'confirmed';
    }

    /** @return array<string, mixed> */
    private function movement(Payment $payment, string $timezone, ?string $type = null, ?string $status = null, $occurredAt = null): array
    {
        $operator = $payment->voidedBy ?? $payment->confirmedBy ?? $payment->rejectedBy ?? $payment->createdBy;
        $customer = $payment->customer ?? $payment->order?->payerCustomer;

        return [
            'id' => (string) $payment->id,
            'occurredAt' => ($occurredAt ?? $payment->voided_at ?? $payment->confirmed_at ?? $payment->paid_at ?? $payment->created_at)?->setTimezone($timezone)->toIso8601String(),
            'origin' => $payment->order?->origin_channel ?: 'manual',
            'orderId' => $payment->order_id ? (string) $payment->order_id : null,
            'orderCode' => $payment->order?->code,
            'customerName' => $customer?->name ?? $payment->order?->customer_name_snapshot ?? 'Cliente nao identificado',
            'type' => $type ?? ($payment->voided_at ? 'void' : 'payment'),
            'method' => $payment->method,
            'status' => $status ?? ($payment->voided_at ? 'voided' : $payment->status),
            'amount' => $this->money((int) ($payment->confirmed_amount_cents ?: $payment->amount_cents)),
            'operatorName' => $operator?->name,
            'notes' => $payment->void_reason ?: $payment->rejection_reason ?: $payment->notes,
            'canVoid' => $payment->status === Payment::STATUS_CONFIRMED && $payment->voided_at === null,
        ];
    }

    /** @param Collection<int, Payment> $confirmed */
    private function methods(Collection $confirmed): array
    {
        return $confirmed->groupBy('method')->map(function (Collection $payments, string $method): array {
            return [
                'method' => $method,
                'count' => $payments->count(),
                'amount' => $this->money((int) $payments->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents))),
            ];
        })->values()->all();
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** @param array{from: mixed, to: mixed} $period */
    private function insidePeriod($date, array $period): bool
    {
        return $date !== null && $date->betweenIncluded($period['from'], $period['to']);
    }
}
