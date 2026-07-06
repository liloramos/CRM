<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\CustomerCreditMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentWorkflowService
{
    public function __construct(private readonly OrderWorkflowService $orders) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordPayment(Order $order, array $attributes = []): Payment
    {
        return DB::transaction(function () use ($order, $attributes): Payment {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $method = (string) ($attributes['method'] ?? Payment::METHOD_PIX);
            $status = (string) ($attributes['status'] ?? $this->defaultStatusFor($method));

            $this->assertPaymentMethod($method);
            $this->assertPaymentStatus($status);

            $amountCents = $this->positiveOrDefault(
                $attributes['amount_cents'] ?? null,
                $this->defaultAmountFor($order),
            );

            $payment = Payment::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'customer_id' => $attributes['customer_id'] ?? $order->payer_customer_id,
                'created_by_user_id' => $attributes['created_by_user_id'] ?? null,
                'method' => $method,
                'provider' => $attributes['provider'] ?? Payment::PROVIDER_MANUAL,
                'status' => $status,
                'amount_cents' => $amountCents,
                'confirmed_amount_cents' => $status === Payment::STATUS_CONFIRMED ? $amountCents : 0,
                'amount_due_after_payment_cents' => max((int) ($order->amount_due_cents ?: $order->total_cents), 0),
                'currency' => $attributes['currency'] ?? $order->currency,
                'external_reference' => $attributes['external_reference'] ?? null,
                'overpayment_action' => $attributes['overpayment_action'] ?? null,
                'paid_at' => $attributes['paid_at'] ?? null,
                'confirmed_at' => $status === Payment::STATUS_CONFIRMED ? now() : null,
                'notes' => $attributes['notes'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            $this->transitionOrderToWaitingStatus($order, $payment, null);
            $this->recalculateOrderPaymentSummary($order);

            return $payment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attachProof(Payment $payment, array $attributes = []): PaymentProof
    {
        return DB::transaction(function () use ($payment, $attributes): PaymentProof {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

            $proof = PaymentProof::query()->create([
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'uploaded_by_user_id' => $attributes['uploaded_by_user_id'] ?? null,
                'source_channel' => $attributes['source_channel'] ?? PaymentProof::SOURCE_MANUAL,
                'storage_disk' => $attributes['storage_disk'] ?? null,
                'file_path' => $attributes['file_path'] ?? null,
                'original_filename' => $attributes['original_filename'] ?? null,
                'mime_type' => $attributes['mime_type'] ?? null,
                'amount_cents' => $attributes['amount_cents'] ?? null,
                'status' => $attributes['status'] ?? PaymentProof::STATUS_RECEIVED,
                'received_at' => $attributes['received_at'] ?? now(),
                'review_notes' => $attributes['review_notes'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            if (! in_array($payment->status, [Payment::STATUS_CONFIRMED, Payment::STATUS_REJECTED], true)) {
                $payment->forceFill(['status' => Payment::STATUS_PROOF_RECEIVED])->save();
            }

            $this->transitionIfOpen(
                $order,
                Order::STATUS_PAYMENT_PROOF_RECEIVED,
                null,
                'payment_proof_received',
                ['payment_id' => $payment->id, 'proof_id' => $proof->id],
            );
            $this->recalculateOrderPaymentSummary($order);

            return $proof->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function confirmPayment(Payment $payment, ?User $user = null, array $attributes = []): Payment
    {
        return DB::transaction(function () use ($payment, $user, $attributes): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (in_array($payment->status, [Payment::STATUS_REJECTED, Payment::STATUS_CANCELLED], true)) {
                throw new DomainException('Rejected or cancelled payments cannot be confirmed.');
            }

            $confirmedAmountCents = $this->positiveOrDefault(
                $attributes['confirmed_amount_cents'] ?? null,
                (int) $payment->amount_cents,
            );
            $overpaymentAction = (string) (
                $attributes['overpayment_action']
                ?? $payment->overpayment_action
                ?? Payment::OVERPAYMENT_PENDING_REVIEW
            );

            $payment->forceFill([
                'status' => Payment::STATUS_CONFIRMED,
                'confirmed_amount_cents' => $confirmedAmountCents,
                'confirmed_by_user_id' => $user?->id,
                'confirmed_at' => $attributes['confirmed_at'] ?? now(),
                'paid_at' => $attributes['paid_at'] ?? $payment->paid_at ?? now(),
                'overpayment_action' => $overpaymentAction,
                'notes' => $attributes['notes'] ?? $payment->notes,
            ])->save();

            $order = $this->recalculateOrderPaymentSummary($payment->order()->firstOrFail());
            $this->handleOverpayment($order, $payment, $user, $overpaymentAction, $attributes['credit_notes'] ?? null);
            $order = $this->recalculateOrderPaymentSummary($order);

            $payment->forceFill([
                'amount_due_after_payment_cents' => (int) $order->amount_due_cents,
            ])->save();

            $this->transitionOrderAfterSummary($order, $user, 'payment_confirmed', ['payment_id' => $payment->id]);

            return $payment->refresh();
        });
    }

    public function rejectPayment(Payment $payment, ?User $user = null, ?string $reason = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($payment, $user, $reason, $notes): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === Payment::STATUS_CONFIRMED) {
                throw new DomainException('Confirmed payments cannot be rejected.');
            }

            $payment->forceFill([
                'status' => Payment::STATUS_REJECTED,
                'rejected_by_user_id' => $user?->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'notes' => $notes ?? $payment->notes,
            ])->save();

            $order = $this->recalculateOrderPaymentSummary($payment->order()->firstOrFail());

            $this->transitionIfOpen(
                $order,
                Order::STATUS_PAYMENT_REJECTED,
                $user,
                'payment_rejected',
                ['payment_id' => $payment->id, 'reason' => $reason],
            );

            return $payment->refresh();
        });
    }

    public function applyCreditToOrder(Order $order, Customer $customer, int $amountCents, ?User $user = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($order, $customer, $amountCents, $user, $notes): Payment {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertPositiveAmount($amountCents);
            $this->assertSameCompany($order, $customer);

            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ((int) $customer->credit_balance_cents < $amountCents) {
                throw new DomainException('Customer credit balance is not enough for this order.');
            }

            $payment = Payment::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'created_by_user_id' => $user?->id,
                'confirmed_by_user_id' => $user?->id,
                'method' => Payment::METHOD_CUSTOMER_CREDIT,
                'provider' => Payment::PROVIDER_MANUAL,
                'status' => Payment::STATUS_CONFIRMED,
                'amount_cents' => $amountCents,
                'confirmed_amount_cents' => $amountCents,
                'currency' => $order->currency,
                'paid_at' => now(),
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);

            $this->createCreditMovement($customer, [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'created_by_user_id' => $user?->id,
                'type' => CustomerCreditMovement::TYPE_CREDIT_USED,
                'direction' => CustomerCreditMovement::DIRECTION_DEBIT,
                'amount_cents' => $amountCents,
                'currency' => $order->currency,
                'reason' => 'credit_applied_to_order',
                'notes' => $notes,
            ]);

            $order = $this->recalculateOrderPaymentSummary($order);
            $payment->forceFill(['amount_due_after_payment_cents' => (int) $order->amount_due_cents])->save();
            $this->transitionOrderAfterSummary($order, $user, 'customer_credit_applied', ['payment_id' => $payment->id]);

            return $payment->refresh();
        });
    }

    public function recalculateOrderPaymentSummary(Order $order): Order
    {
        $order = Order::query()->whereKey($order->id)->firstOrFail();

        /** @var Collection<int, Payment> $confirmedPayments */
        $confirmedPayments = $order->payments()
            ->where('status', Payment::STATUS_CONFIRMED)
            ->get();

        $amountPaid = $confirmedPayments->sum(
            fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents),
        );
        $creditUsed = $confirmedPayments
            ->where('method', Payment::METHOD_CUSTOMER_CREDIT)
            ->sum(fn (Payment $payment): int => (int) ($payment->confirmed_amount_cents ?: $payment->amount_cents));
        $creditGenerated = (int) $order->creditMovements()
            ->where('type', CustomerCreditMovement::TYPE_CREDIT_GENERATED)
            ->sum('amount_cents');
        $total = max((int) $order->total_cents, 0);
        $amountDue = max($total - $amountPaid, 0);
        $paymentStatus = $this->resolveOrderPaymentStatus($order, $amountPaid, $amountDue);
        $paymentMethod = $this->resolveOrderPaymentMethod($order, $confirmedPayments);
        $lastPaymentAt = $this->resolveLastPaymentAt($confirmedPayments);

        $order->forceFill([
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'amount_paid_cents' => $amountPaid,
            'amount_due_cents' => $amountDue,
            'credit_used_cents' => $creditUsed,
            'credit_generated_cents' => $creditGenerated,
            'last_payment_at' => $lastPaymentAt,
            'payment_confirmed_at' => in_array($paymentStatus, [Payment::ORDER_STATUS_PAID, Payment::ORDER_STATUS_OVERPAID], true)
                ? $lastPaymentAt
                : null,
        ])->save();

        return $order->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCreditMovement(Customer $customer, array $attributes): CustomerCreditMovement
    {
        return DB::transaction(function () use ($customer, $attributes): CustomerCreditMovement {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $direction = (string) $attributes['direction'];
            $amountCents = (int) $attributes['amount_cents'];

            $this->assertPositiveAmount($amountCents);

            $balanceBefore = (int) $customer->credit_balance_cents;
            $balanceAfter = match ($direction) {
                CustomerCreditMovement::DIRECTION_CREDIT => $balanceBefore + $amountCents,
                CustomerCreditMovement::DIRECTION_DEBIT => $balanceBefore - $amountCents,
                default => throw new DomainException("Unsupported credit movement direction [{$direction}]."),
            };

            if ($direction === CustomerCreditMovement::DIRECTION_DEBIT && $balanceAfter < 0) {
                throw new DomainException('Customer credit balance cannot become negative.');
            }

            $movement = CustomerCreditMovement::query()->create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'order_id' => $attributes['order_id'] ?? null,
                'payment_id' => $attributes['payment_id'] ?? null,
                'created_by_user_id' => $attributes['created_by_user_id'] ?? null,
                'type' => $attributes['type'],
                'direction' => $direction,
                'amount_cents' => $amountCents,
                'balance_before_cents' => $balanceBefore,
                'balance_after_cents' => $balanceAfter,
                'currency' => $attributes['currency'] ?? $customer->credit_currency,
                'reason' => $attributes['reason'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            $customer->forceFill([
                'credit_balance_cents' => $balanceAfter,
                'credit_currency' => $movement->currency,
            ])->save();

            return $movement->refresh();
        });
    }

    private function handleOverpayment(
        Order $order,
        Payment $payment,
        ?User $user,
        string $overpaymentAction,
        ?string $notes,
    ): void {
        if ($overpaymentAction !== Payment::OVERPAYMENT_KEEP_AS_CREDIT) {
            return;
        }

        $overpaidAmount = max((int) $order->amount_paid_cents - (int) $order->total_cents, 0);
        $alreadyGenerated = (int) $order->creditMovements()
            ->where('type', CustomerCreditMovement::TYPE_CREDIT_GENERATED)
            ->sum('amount_cents');
        $amountToGenerate = $overpaidAmount - $alreadyGenerated;

        if ($amountToGenerate <= 0 || ! $order->payer_customer_id) {
            return;
        }

        $customer = Customer::query()->findOrFail($order->payer_customer_id);

        $this->createCreditMovement($customer, [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'created_by_user_id' => $user?->id,
            'type' => CustomerCreditMovement::TYPE_CREDIT_GENERATED,
            'direction' => CustomerCreditMovement::DIRECTION_CREDIT,
            'amount_cents' => $amountToGenerate,
            'currency' => $order->currency,
            'reason' => 'overpayment_kept_as_credit',
            'notes' => $notes,
        ]);
    }

    private function transitionOrderToWaitingStatus(Order $order, Payment $payment, ?User $user): void
    {
        $targetStatus = match ($payment->status) {
            Payment::STATUS_AWAITING_PROOF => Order::STATUS_AWAITING_PAYMENT_PROOF,
            Payment::STATUS_PENDING => Order::STATUS_AWAITING_PAYMENT,
            Payment::STATUS_PROOF_RECEIVED => Order::STATUS_PAYMENT_PROOF_RECEIVED,
            Payment::STATUS_CONFIRMED => Order::STATUS_PAYMENT_CONFIRMED,
            Payment::STATUS_REJECTED => Order::STATUS_PAYMENT_REJECTED,
            default => null,
        };

        if ($targetStatus === null) {
            return;
        }

        $this->transitionIfOpen(
            $order,
            $targetStatus,
            $user,
            'payment_registered',
            ['payment_id' => $payment->id, 'method' => $payment->method],
        );
    }

    private function transitionOrderAfterSummary(Order $order, ?User $user, string $reason, array $metadata): void
    {
        $targetStatus = match ($order->payment_status) {
            Payment::ORDER_STATUS_PAID, Payment::ORDER_STATUS_OVERPAID => Order::STATUS_PAYMENT_CONFIRMED,
            Payment::ORDER_STATUS_REJECTED => Order::STATUS_PAYMENT_REJECTED,
            Payment::ORDER_STATUS_PENDING, Payment::ORDER_STATUS_PARTIAL => Order::STATUS_AWAITING_PAYMENT,
            default => null,
        };

        if ($targetStatus === null) {
            return;
        }

        $this->transitionIfOpen($order, $targetStatus, $user, $reason, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function transitionIfOpen(
        Order $order,
        string $status,
        ?User $user,
        string $reason,
        array $metadata = [],
    ): void {
        $order = $order->refresh();

        if ($order->status === $status || in_array($order->status, Order::LOCKED_STATUSES, true)) {
            return;
        }

        $this->orders->transitionTo($order, $status, $user, $reason, metadata: $metadata);
    }

    private function resolveOrderPaymentStatus(Order $order, int $amountPaid, int $amountDue): string
    {
        $total = (int) $order->total_cents;

        if ($total > 0 && $amountPaid > $total) {
            return Payment::ORDER_STATUS_OVERPAID;
        }

        if ($total > 0 && $amountPaid === $total) {
            return Payment::ORDER_STATUS_PAID;
        }

        if ($amountPaid > 0 && $amountDue > 0) {
            return Payment::ORDER_STATUS_PARTIAL;
        }

        $hasOpenPayment = $order->payments()
            ->whereIn('status', [
                Payment::STATUS_PENDING,
                Payment::STATUS_AWAITING_PROOF,
                Payment::STATUS_PROOF_RECEIVED,
            ])
            ->exists();

        if ($hasOpenPayment) {
            return Payment::ORDER_STATUS_PENDING;
        }

        $hasRejectedPayment = $order->payments()
            ->where('status', Payment::STATUS_REJECTED)
            ->exists();

        return $hasRejectedPayment
            ? Payment::ORDER_STATUS_REJECTED
            : Payment::ORDER_STATUS_UNPAID;
    }

    /**
     * @param  Collection<int, Payment>  $confirmedPayments
     */
    private function resolveOrderPaymentMethod(Order $order, Collection $confirmedPayments): ?string
    {
        $confirmedMethods = $confirmedPayments
            ->pluck('method')
            ->unique()
            ->values();

        if ($confirmedMethods->count() === 1) {
            return (string) $confirmedMethods->first();
        }

        if ($confirmedMethods->count() > 1) {
            return Payment::METHOD_MIXED;
        }

        return $order->payments()->latest('id')->value('method');
    }

    /**
     * @param  Collection<int, Payment>  $confirmedPayments
     */
    private function resolveLastPaymentAt(Collection $confirmedPayments): mixed
    {
        $latestPayment = $confirmedPayments
            ->sortByDesc(fn (Payment $payment): int => (int) optional($payment->confirmed_at ?? $payment->paid_at ?? $payment->updated_at)->getTimestamp())
            ->first();

        return $latestPayment?->confirmed_at ?? $latestPayment?->paid_at;
    }

    private function defaultStatusFor(string $method): string
    {
        return match ($method) {
            Payment::METHOD_PIX => Payment::STATUS_AWAITING_PROOF,
            Payment::METHOD_CUSTOMER_CREDIT => Payment::STATUS_CONFIRMED,
            default => Payment::STATUS_PENDING,
        };
    }

    private function defaultAmountFor(Order $order): int
    {
        $amountDue = (int) ($order->amount_due_cents ?? 0);

        if ($amountDue > 0) {
            return $amountDue;
        }

        return max((int) $order->total_cents - (int) ($order->amount_paid_cents ?? 0), 0);
    }

    private function positiveOrDefault(mixed $value, int $default): int
    {
        $amount = $value === null ? $default : (int) $value;
        $this->assertPositiveAmount($amount);

        return $amount;
    }

    private function assertPositiveAmount(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new DomainException('Payment amounts must be greater than zero.');
        }
    }

    private function assertPaymentMethod(string $method): void
    {
        if (! in_array($method, Payment::METHODS, true)) {
            throw new DomainException("Unsupported payment method [{$method}].");
        }
    }

    private function assertPaymentStatus(string $status): void
    {
        if (! in_array($status, Payment::STATUSES, true)) {
            throw new DomainException("Unsupported payment status [{$status}].");
        }
    }

    private function assertSameCompany(Order $order, Customer $customer): void
    {
        if ((int) $order->company_id !== (int) $customer->company_id) {
            throw new DomainException('Customer credit can only be used by orders from the same company.');
        }
    }
}
