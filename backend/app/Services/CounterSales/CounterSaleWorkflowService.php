<?php

namespace App\Services\CounterSales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CounterSaleWorkflowService
{
    private const DRAFT_PRODUCT_RULE_CODES = [
        'self_service_counter',
        'counter_weight_standard',
        'counter_weight_meat_only',
    ];

    public function __construct(
        private readonly CounterSaleProductEligibility $eligibility,
        private readonly CounterSalePricingService $pricing,
        private readonly OrderWorkflowService $orders,
        private readonly PaymentWorkflowService $payments,
    ) {}

    /** @param list<array{product_id:int, quantity:int, weight_grams?:int, selected_components?:list<string>, additions?:list<array{code:string, quantity:int}>}> $items */
    public function complete(Company $company, User $user, array $items, string $paymentMethod, array $customerAttributes = [], ?int $sellerUserId = null): Order
    {
        if ($items === []) {
            throw new DomainException('Adicione ao menos um produto para concluir a venda.');
        }

        return DB::transaction(function () use ($company, $user, $items, $paymentMethod, $customerAttributes, $sellerUserId): Order {
            $saleDate = $this->saleDate($company);
            $order = $this->createCounterOrder(
                $company,
                $user,
                $saleDate,
                $customerAttributes,
                null,
                'Venda de balcão iniciada pela atendente.',
                $sellerUserId,
            );

            foreach ($items as $line) {
                $product = $this->eligibleProduct($company, (int) $line['product_id'], $saleDate);
                $this->orders->addItem($order, $product, $this->pricing->itemAttributes($company, $product, $line));
            }

            return $this->completeOrder($order, $user, $paymentMethod);
        });
    }

    /** @param array<string, mixed> $line */
    public function openDraft(Company $company, User $user, array $line, ?string $notes = null, array $customerAttributes = [], ?int $sellerUserId = null): Order
    {
        return DB::transaction(function () use ($company, $user, $line, $notes, $customerAttributes, $sellerUserId): Order {
            $saleDate = $this->saleDate($company);
            $product = $this->eligibleProduct($company, (int) $line['product_id'], $saleDate);
            if (! in_array($product->menu_rule_code, self::DRAFT_PRODUCT_RULE_CODES, true)) {
                throw new DomainException('Este produto não utiliza comanda operacional no Caixa.');
            }
            $order = $this->createCounterOrder(
                $company,
                $user,
                $saleDate,
                $customerAttributes,
                $notes,
                'Comanda de balcão aberta sem pagamento.',
                $sellerUserId,
            );

            $this->orders->addItem(
                $order,
                $product,
                $this->pricing->draftItemAttributes($company, $product, $line),
            );

            $order->statusHistories()->create([
                'user_id' => $user->id,
                'from_status' => Order::STATUS_DRAFT,
                'to_status' => Order::STATUS_DRAFT,
                'reason' => 'counter_sale_draft_opened',
                'notes' => 'Comanda operacional de balcão aberta sem pagamento.',
                'metadata' => ['product_id' => $product->id, 'menu_rule_code' => $product->menu_rule_code],
            ]);

            return $order->refresh();
        });
    }

    public function updateDraftCustomer(
        Company $company,
        User $user,
        Order $order,
        array $customerAttributes,
    ): Order {
        return DB::transaction(function () use ($company, $user, $order, $customerAttributes): Order {
            $order = Order::query()
                ->where('company_id', $company->id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $order instanceof Order || ! $this->isCounterSale($order)) {
                throw new DomainException('Esta comanda de balcão não pertence ao restaurante atual.');
            }

            if (
                $order->status !== Order::STATUS_DRAFT
                || ! $order->statusHistories()->where('reason', 'counter_sale_draft_opened')->exists()
            ) {
                throw new DomainException('O cliente só pode ser alterado enquanto a comanda estiver aberta.');
            }

            $previousCustomerId = $order->payer_customer_id;
            $customer = $this->applyCustomerContext($company, $order, $customerAttributes);

            $order->statusHistories()->create([
                'user_id' => $user->id,
                'from_status' => Order::STATUS_DRAFT,
                'to_status' => Order::STATUS_DRAFT,
                'reason' => 'counter_sale_draft_customer_updated',
                'notes' => $customer
                    ? 'Cliente vinculado à comanda aberta.'
                    : 'Cliente removido da comanda aberta.',
                'metadata' => [
                    'previous_customer_id' => $previousCustomerId,
                    'customer_id' => $customer?->id,
                ],
            ]);

            return $order->refresh()->load('payerCustomer');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function finalizeDraft(
        Company $company,
        User $user,
        Order $order,
        array $attributes,
        string $paymentMethod,
    ): Order {
        return DB::transaction(function () use ($company, $user, $order, $attributes, $paymentMethod): Order {
            $order = Order::query()
                ->where('company_id', $company->id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $order instanceof Order || ! $this->isCounterSale($order)) {
                throw new DomainException('Esta comanda de balcão não pertence ao restaurante atual.');
            }

            if ($order->status === Order::STATUS_FINISHED) {
                return $order->refresh();
            }

            if ($order->status === Order::STATUS_CANCELLED) {
                throw new DomainException('Uma comanda de balcão cancelada não pode ser finalizada.');
            }

            if ($order->status !== Order::STATUS_DRAFT) {
                throw new DomainException('Somente uma comanda de balcão aberta pode ser finalizada.');
            }

            if ($order->payments()->exists()) {
                throw new DomainException('A comanda aberta possui um registro financeiro inesperado e precisa de revisão.');
            }

            if ($this->hasCustomerAttributes($attributes)) {
                $this->applyCustomerContext($company, $order, $attributes);
            }

            if (array_key_exists('seller_user_id', $attributes)) {
                $order = $this->orders->assignSeller(
                    $company,
                    $order,
                    $attributes['seller_user_id'] !== null ? (int) $attributes['seller_user_id'] : null,
                    $user,
                );
            }

            $items = $order->items()->with('options')->lockForUpdate()->get();
            if ($items->count() !== 1) {
                throw new DomainException('A comanda de balcão precisa possuir exatamente um item para ser finalizada.');
            }

            /** @var OrderItem $item */
            $item = $items->sole();
            $saleDate = CarbonImmutable::parse(
                $order->order_date,
                $company->setting?->timezone ?: config('app.timezone'),
            );
            $product = $this->eligibleProduct($company, (int) $item->product_id, $saleDate);
            $line = [
                'quantity' => (int) $item->quantity,
                'weight_grams' => array_key_exists('weight_grams', $attributes)
                    ? $attributes['weight_grams']
                    : $item->weight_grams,
                'selected_components' => array_key_exists('selected_components', $attributes)
                    ? $attributes['selected_components']
                    : $item->selected_components,
                'additions' => array_key_exists('additions', $attributes)
                    ? $attributes['additions']
                    : $this->existingAdditions($item),
                'item_notes' => $item->item_notes,
            ];

            $this->orders->updateItem(
                $order,
                $item,
                $product,
                $this->pricing->itemAttributes($company, $product, $line),
                $user,
            );

            if (array_key_exists('notes', $attributes)) {
                $order->forceFill(['general_notes' => $attributes['notes']])->save();
            }

            return $this->completeOrder($order->refresh(), $user, $paymentMethod);
        });
    }

    public function cancel(Company $company, User $user, Order $order, string $reason, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($company, $user, $order, $reason, $notes): Order {
            $order = Order::query()
                ->where('company_id', $company->id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $order instanceof Order || ! $this->isCounterSale($order)) {
                throw new DomainException('Esta venda de balcão não pertence ao restaurante atual.');
            }

            if ($order->status === Order::STATUS_CANCELLED) {
                throw new DomainException('Esta venda de balcão já foi cancelada.');
            }

            if ($order->status === Order::STATUS_DRAFT) {
                if ($order->payments()->exists()) {
                    throw new DomainException('A comanda aberta possui um registro financeiro inesperado e precisa de revisão.');
                }

                return $this->orders->transitionTo(
                    $order,
                    Order::STATUS_CANCELLED,
                    $user,
                    'counter_sale_draft_cancelled',
                    $notes ?: 'Comanda de balcão cancelada sem movimento financeiro.',
                    ['reason' => $reason],
                );
            }

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

    private function createCounterOrder(
        Company $company,
        User $user,
        CarbonImmutable $saleDate,
        array $customerAttributes,
        ?string $notes,
        string $statusNotes,
        ?int $sellerUserId,
    ): Order {
        $customerContext = $this->resolveCustomerContext($company, $customerAttributes);
        $order = $this->orders->createDraft($company, [
            'payer_customer_id' => $customerContext['customer']?->id,
            'customer_name_snapshot' => $customerContext['name'],
            'customer_phone_snapshot' => $customerContext['phone'],
            'created_by_user_id' => $user->id,
            'seller_user_id' => $sellerUserId,
            'order_date' => $saleDate,
            'origin_channel' => Order::CHANNEL_COUNTER,
            'entry_mode' => Order::CHANNEL_COUNTER,
            'fulfillment_type' => Order::FULFILLMENT_COUNTER,
            'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKUP_PENDING,
            'is_manual' => true,
            'customer_confirmation_required' => false,
            'human_review_required' => false,
            'general_notes' => $notes,
            'status_notes' => $statusNotes,
        ]);

        $order->forceFill([
            'pickup_status' => Order::PICKUP_STATUS_PENDING,
            'print_required' => false,
            'print_status' => Order::PRINT_STATUS_WAIVED,
            'print_waived_at' => now(),
            'print_waived_by_user_id' => $user->id,
            'print_waiver_reason' => 'Venda de balcão não exige impressão operacional neste fluxo.',
        ])->save();

        return $order;
    }

    private function completeOrder(Order $order, User $user, string $paymentMethod): Order
    {
        $order = $order->refresh();
        if ((int) $order->total_cents <= 0) {
            throw new DomainException('A venda precisa ter um total maior que zero.');
        }

        $order->forceFill([
            'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKED_UP,
            'pickup_status' => Order::PICKUP_STATUS_PICKED_UP,
        ])->save();

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
    }

    private function eligibleProduct(Company $company, int $productId, CarbonImmutable $saleDate): Product
    {
        $product = Product::query()
            ->with(['category', 'serviceDays'])
            ->where('company_id', $company->id)
            ->whereKey($productId)
            ->first();

        if (! $product instanceof Product || ! $this->eligibility->isEligible($company, $product, $saleDate)) {
            throw new DomainException('Este produto não está disponível para venda no Caixa.');
        }

        return $product;
    }

    /** @param array<string, mixed> $attributes */
    private function applyCustomerContext(Company $company, Order $order, array $attributes): ?Customer
    {
        $context = $this->resolveCustomerContext($company, $attributes, $order);

        $order->forceFill([
            'payer_customer_id' => $context['customer']?->id,
            'customer_name_snapshot' => $context['name'],
            'customer_phone_snapshot' => $context['phone'],
        ])->save();

        return $context['customer'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{customer: ?Customer, name: ?string, phone: ?string}
     */
    private function resolveCustomerContext(Company $company, array $attributes, ?Order $order = null): array
    {
        $customerId = isset($attributes['customer_id']) ? (int) $attributes['customer_id'] : null;
        if ($customerId !== null) {
            $customer = Customer::query()
                ->where('company_id', $company->id)
                ->whereKey($customerId)
                ->first();

            if (! $customer instanceof Customer) {
                throw new DomainException('Este cliente não pertence ao restaurante atual.');
            }

            return ['customer' => $customer, 'name' => $customer->name, 'phone' => $customer->phone];
        }

        $name = Str::squish((string) ($attributes['customer_name'] ?? ''));
        $phone = $this->normalizedPhone($attributes['customer_phone'] ?? null);
        $saveCustomer = (bool) ($attributes['save_customer'] ?? false);

        if ($name === '') {
            if ($saveCustomer) {
                throw new DomainException('Informe o nome para salvar o cliente.');
            }

            return ['customer' => null, 'name' => null, 'phone' => null];
        }

        if (! $saveCustomer) {
            return ['customer' => null, 'name' => $name, 'phone' => $phone];
        }

        if (
            $phone === null
            && $order?->payerCustomer instanceof Customer
            && $order->payerCustomer->source_channel === 'manual'
            && Str::squish($order->payerCustomer->name) === $name
        ) {
            $customer = $order->payerCustomer;

            return ['customer' => $customer, 'name' => $customer->name, 'phone' => $customer->phone];
        }

        $customer = $phone !== null
            ? Customer::query()
                ->where('company_id', $company->id)
                ->whereNotNull('phone')
                ->get()
                ->first(fn (Customer $candidate): bool => $this->normalizedPhone($candidate->phone) === $phone)
            : null;

        if (! $customer instanceof Customer) {
            $customer = Customer::query()->create([
                'company_id' => $company->id,
                'name' => $name,
                'phone' => $phone,
                'source_channel' => 'manual',
            ]);
        }

        return ['customer' => $customer, 'name' => $customer->name, 'phone' => $customer->phone];
    }

    /** @param array<string, mixed> $attributes */
    private function hasCustomerAttributes(array $attributes): bool
    {
        return collect(['customer_id', 'customer_name', 'customer_phone', 'save_customer'])
            ->contains(fn (string $key): bool => array_key_exists($key, $attributes));
    }

    private function normalizedPhone(mixed $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /** @return list<array{code: string, quantity: int}> */
    private function existingAdditions(OrderItem $item): array
    {
        $quantity = (int) $item->options
            ->where('group_code', 'bife_adicional')
            ->sum('quantity');

        return $quantity > 0 ? [['code' => 'extra_beef', 'quantity' => $quantity]] : [];
    }

    private function saleDate(Company $company): CarbonImmutable
    {
        return CarbonImmutable::now($company->setting?->timezone ?: config('app.timezone'));
    }

    private function isCounterSale(Order $order): bool
    {
        return $order->origin_channel === Order::CHANNEL_COUNTER
            && $order->fulfillment_type === Order::FULFILLMENT_COUNTER;
    }
}
