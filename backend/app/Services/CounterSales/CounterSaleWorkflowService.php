<?php

namespace App\Services\CounterSales;

use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CounterSaleWorkflowService
{
    public function __construct(
        private readonly CounterSaleProductEligibility $eligibility,
        private readonly OrderWorkflowService $orders,
        private readonly PaymentWorkflowService $payments,
    ) {}

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function complete(Company $company, User $user, array $items, string $paymentMethod): Order
    {
        if ($items === []) {
            throw new DomainException('Adicione ao menos um produto para concluir a venda.');
        }

        return DB::transaction(function () use ($company, $user, $items, $paymentMethod): Order {
            $saleDate = CarbonImmutable::now($company->setting?->timezone ?: config('app.timezone'));
            $order = $this->orders->createDraft($company, [
                'created_by_user_id' => $user->id,
                'order_date' => $saleDate,
                'origin_channel' => Order::CHANNEL_COUNTER,
                'entry_mode' => Order::CHANNEL_COUNTER,
                'fulfillment_type' => Order::FULFILLMENT_COUNTER,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKED_UP,
                'is_manual' => true,
                'customer_confirmation_required' => false,
                'human_review_required' => false,
                'status_notes' => 'Venda de balcão iniciada pela atendente.',
            ]);

            $order->forceFill([
                'pickup_status' => Order::PICKUP_STATUS_PICKED_UP,
                'print_required' => false,
                'print_status' => Order::PRINT_STATUS_WAIVED,
                'print_waived_at' => now(),
                'print_waived_by_user_id' => $user->id,
                'print_waiver_reason' => 'Venda de balcão não exige impressão neste MVP.',
            ])->save();

            collect($items)
                ->groupBy('product_id')
                ->each(function ($lines, int|string $productId) use ($company, $saleDate, $order): void {
                    $product = Product::query()
                        ->with(['category', 'serviceDays'])
                        ->where('company_id', $company->id)
                        ->whereKey($productId)
                        ->first();

                    if (! $product instanceof Product || ! $this->eligibility->isEligible($company, $product, $saleDate)) {
                        throw new DomainException('Este produto não está disponível para venda no Caixa.');
                    }

                    $quantity = $lines->sum(fn (array $line): int => (int) $line['quantity']);
                    $this->orders->addItem($order, $product, [
                        'quantity' => $quantity,
                        'unit_price_cents' => (int) $product->base_price_cents,
                    ]);
                });

            $order = $order->refresh();
            if ((int) $order->total_cents <= 0) {
                throw new DomainException('A venda precisa ter um total maior que zero.');
            }

            $this->payments->confirmOrderPayment($order, $user, [
                'method' => $paymentMethod,
                'amount_cents' => (int) $order->total_cents,
                'notes' => 'Pagamento confirmado pela atendente no Caixa.',
                'metadata' => ['source' => 'counter_sale'],
            ]);

            return $this->orders->transitionTo(
                $order->refresh(),
                Order::STATUS_FINISHED,
                $user,
                'counter_sale_completed',
                'Venda de balcão concluída com pagamento confirmado.',
                ['origin_channel' => Order::CHANNEL_COUNTER, 'payment_method' => $paymentMethod],
            );
        });
    }

    public function cancel(Company $company, User $user, Order $order, string $reason, ?string $notes = null): Order
    {
        if ((int) $order->company_id !== (int) $company->id || ! $this->isCounterSale($order)) {
            throw new DomainException('Esta venda de balcão não pertence ao restaurante atual.');
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw new DomainException('Esta venda de balcão já foi cancelada.');
        }

        return DB::transaction(function () use ($order, $user, $reason, $notes): Order {
            $payment = $this->payments->voidLatestConfirmedPayment($order, $user, $reason);

            return $this->orders->transitionTo(
                $order->refresh(),
                Order::STATUS_CANCELLED,
                $user,
                'counter_sale_cancelled',
                $notes ?: 'Venda de balcão cancelada pela atendente; pagamento anulado sem estorno automático.',
                ['payment_id' => $payment->id, 'reason' => $reason],
            );
        });
    }

    private function isCounterSale(Order $order): bool
    {
        return $order->origin_channel === Order::CHANNEL_COUNTER
            && $order->fulfillment_type === Order::FULFILLMENT_COUNTER;
    }
}
