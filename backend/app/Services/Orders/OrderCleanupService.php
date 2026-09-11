<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OrderCleanupService
{
    public const BLOCKED_CODE = 'order_not_eligible_for_permanent_deletion';

    public const BULK_BLOCKED_CODE = 'orders_not_eligible_for_permanent_deletion';

    /**
     * @return array{deleted: int, order_ids: list<string>}
     */
    public function deleteOneForTesting(Company $company, Order $order): array
    {
        return $this->deleteManyForTesting($company, [(int) $order->id]);
    }

    /**
     * @return array{can_permanently_delete_orders: bool, can_run_destructive_test_cleanup: bool, destructive_cleanup_environment: string}
     */
    public function capabilities(?User $actor): array
    {
        $canManageOrders = (bool) $actor?->hasPermissionTo('orders.manage');

        return [
            'can_permanently_delete_orders' => $this->canPermanentlyDeleteOrders($actor),
            'can_run_destructive_test_cleanup' => $canManageOrders && $this->destructiveTestCleanupEnabled(),
            'destructive_cleanup_environment' => $this->currentEnvironment(),
        ];
    }

    public function canPermanentlyDeleteOrders(?User $actor): bool
    {
        return (bool) $actor?->hasPermissionTo('orders.manage')
            && $actor->hasAnyRole([Role::SUPER_ADMIN, Role::ADMIN_GERENTE]);
    }

    /**
     * @return array{deleted: int, order_ids: list<string>, eligible: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function deleteOnePermanently(Company $company, Order $order, User $actor): array
    {
        return $this->deleteManyPermanently($company, [(int) $order->id], $actor);
    }

    /**
     * @param  list<int>  $orderIds
     * @return array{deleted: int, order_ids: list<string>, eligible: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function deleteManyPermanently(Company $company, array $orderIds, User $actor): array
    {
        $this->assertCanPermanentlyDeleteOrders($actor);
        $orderIds = $this->normalizeOrderIds($orderIds);

        return DB::transaction(function () use ($company, $orderIds): array {
            $preview = $this->previewPermanentDeletion($company, $orderIds, lockForUpdate: true);

            if ($preview['blocked'] !== []) {
                return [
                    'deleted' => 0,
                    'order_ids' => [],
                    ...$preview,
                ];
            }

            $deletedIds = [];
            $orders = Order::query()
                ->where('company_id', $company->id)
                ->whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($orderIds as $orderId) {
                $order = $orders[$orderId];
                $this->deleteOrderGraph($order);
                $deletedIds[] = (string) $orderId;
            }

            return [
                'deleted' => count($deletedIds),
                'order_ids' => $deletedIds,
                ...$preview,
            ];
        });
    }

    /**
     * @param  list<int>  $orderIds
     * @return array{eligible: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function previewManyPermanently(Company $company, array $orderIds, User $actor): array
    {
        $this->assertCanPermanentlyDeleteOrders($actor);

        return $this->previewPermanentDeletion($company, $orderIds);
    }

    /**
     * @param  list<int>  $orderIds
     * @return array{eligible: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function previewPermanentDeletion(Company $company, array $orderIds, bool $lockForUpdate = false): array
    {
        $orderIds = $this->normalizeOrderIds($orderIds);

        $query = Order::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $orderIds)
            ->withCount([
                'payments',
                'paymentProofs',
                'creditMovements',
                'deliveryQuotes',
                'fragments',
                'printJobs',
                'printJobs as confirmed_print_jobs_count' => fn ($query) => $query->whereIn('status', [
                    PrintJob::STATUS_PRINTING,
                    PrintJob::STATUS_PRINTED,
                    PrintJob::STATUS_MANUAL_CONFIRMED,
                ]),
                'payments as confirmed_payments_count' => fn ($query) => $query->whereIn('status', [
                    Payment::STATUS_CONFIRMED,
                ]),
                'payments as reviewable_payments_count' => fn ($query) => $query->whereIn('status', [
                    Payment::STATUS_PENDING,
                    Payment::STATUS_AWAITING_PROOF,
                    Payment::STATUS_PROOF_RECEIVED,
                ]),
            ]);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $orders = $query->get()->keyBy('id');
        $eligible = [];
        $blocked = [];

        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);

            if (! $order) {
                $blocked[] = [
                    'order_id' => (string) $orderId,
                    'code' => null,
                    'reasons' => ['order_not_found'],
                ];

                continue;
            }

            $reasons = $this->permanentDeletionBlockReasons($order);

            if ($reasons === []) {
                $eligible[] = $this->orderDeletionSummary($order);

                continue;
            }

            $blocked[] = [
                ...$this->orderDeletionSummary($order),
                'reasons' => $reasons,
            ];
        }

        return [
            'eligible' => $eligible,
            'blocked' => $blocked,
        ];
    }

    /**
     * @param  list<int>  $orderIds
     * @return array{deleted: int, order_ids: list<string>}
     */
    public function deleteManyForTesting(Company $company, array $orderIds): array
    {
        $this->assertDestructiveCleanupAllowed();
        $orderIds = $this->normalizeOrderIds($orderIds);

        return DB::transaction(function () use ($company, $orderIds): array {
            $orders = Order::query()
                ->where('company_id', $company->id)
                ->whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($orders->count() !== count($orderIds)) {
                throw new DomainException('Um ou mais pedidos selecionados nao foram encontrados para esta empresa.');
            }

            $deletedIds = [];

            foreach ($orderIds as $orderId) {
                $order = $orders[$orderId];
                $this->deleteOrderGraph($order);
                $deletedIds[] = (string) $orderId;
            }

            return [
                'deleted' => count($deletedIds),
                'order_ids' => $deletedIds,
            ];
        });
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function normalizeOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(
            $orderIds,
            fn (int $orderId): bool => $orderId > 0,
        )));

        if ($orderIds === []) {
            throw new DomainException('Selecione ao menos um pedido para exclusao.');
        }

        return $orderIds;
    }

    /**
     * @return list<string>
     */
    private function permanentDeletionBlockReasons(Order $order): array
    {
        $reasons = [];

        if ($order->status !== Order::STATUS_CANCELLED) {
            $reasons[] = 'status_not_eligible';
        }

        if (in_array($order->status, [
            Order::STATUS_IN_PREPARATION,
            Order::STATUS_READY_FOR_PICKUP,
            Order::STATUS_OUT_FOR_DELIVERY,
            Order::STATUS_FINISHED,
        ], true)) {
            $reasons[] = 'preparation_started';
        }

        if (
            in_array($order->payment_status, [
                Payment::ORDER_STATUS_PAID,
                Payment::ORDER_STATUS_PARTIAL,
                Payment::ORDER_STATUS_OVERPAID,
            ], true)
            || (int) $order->amount_paid_cents > 0
            || (int) $order->credit_used_cents > 0
            || (int) $order->credit_generated_cents > 0
            || (int) ($order->confirmed_payments_count ?? 0) > 0
        ) {
            $reasons[] = 'payment_confirmed';
        }

        if ((int) ($order->reviewable_payments_count ?? 0) > 0) {
            $reasons[] = 'payment_review_pending';
        }

        if ((int) ($order->credit_movements_count ?? 0) > 0) {
            $reasons[] = 'financial_movement';
        }

        if (
            in_array($order->print_status, [
                Order::PRINT_STATUS_PRINTING,
                Order::PRINT_STATUS_PRINTED,
                Order::PRINT_STATUS_MANUAL_CONFIRMED,
            ], true)
            || (int) ($order->confirmed_print_jobs_count ?? 0) > 0
            || $order->printed_at !== null
        ) {
            $reasons[] = 'print_confirmed';
        }

        if ($order->conversation_id !== null || (int) ($order->fragments_count ?? 0) > 0) {
            $reasons[] = 'conversation_linked';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array{order_id: string, code: string|null}
     */
    private function orderDeletionSummary(Order $order): array
    {
        return [
            'order_id' => (string) $order->id,
            'code' => $order->code,
        ];
    }

    private function deleteOrderGraph(Order $order): void
    {
        $order->forceFill(['latest_print_job_id' => null])->save();
        $order->printJobs()->update(['parent_print_job_id' => null]);
        $order->paymentProofs()->delete();
        $order->creditMovements()->delete();
        $order->printJobEvents()->delete();
        $order->printJobs()->delete();
        $order->delete();
    }

    private function assertCanPermanentlyDeleteOrders(User $actor): void
    {
        if (! $this->canPermanentlyDeleteOrders($actor)) {
            throw new AuthorizationException('A exclusao permanente exige acesso administrativo privilegiado.');
        }
    }

    private function assertDestructiveCleanupAllowed(): void
    {
        if (! $this->environmentAllowsDestructiveCleanup()) {
            throw new DomainException('A limpeza ampla de registros de teste nao esta disponivel neste ambiente.');
        }

        if (! $this->destructiveTestCleanupEnabled()) {
            throw new DomainException('A limpeza ampla de registros de teste esta desativada neste ambiente.');
        }
    }

    private function destructiveTestCleanupEnabled(): bool
    {
        return $this->environmentAllowsDestructiveCleanup()
            && (bool) config('chatbotcrm.orders.allow_destructive_test_cleanup');
    }

    private function environmentAllowsDestructiveCleanup(): bool
    {
        return in_array($this->currentEnvironment(), ['local', 'testing', 'development'], true);
    }

    private function currentEnvironment(): string
    {
        return (string) config('app.env', 'production');
    }
}
