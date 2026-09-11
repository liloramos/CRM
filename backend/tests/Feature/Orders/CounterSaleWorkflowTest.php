<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroupComponent;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\CopilotProductEligibility;
use App\Services\Operational\OperationalCrmPresenter;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CounterSaleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_counter_catalog_only_returns_active_available_products_from_the_current_company(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $visible = $this->counterProduct($company, 'Geladinho de morango', 350);
        $this->counterProduct($company, 'Produto inativo', 400, isActive: false);
        $this->counterProduct($company, 'Produto indisponível', 400, isAvailable: false);

        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $this->counterProduct($otherCompany, 'Produto externo', 500);

        $products = $this->actingAs($user)
            ->getJson('/api/app/counter-sales/products')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['id' => $visible->id, 'base_price_cents' => 350])
            ->assertJsonFragment(['slug' => 'self-service', 'base_price_cents' => 1900])
            ->assertJsonFragment(['slug' => 'comida-por-kg-comum', 'menu_rule_code' => 'counter_weight_standard', 'base_price_cents' => 5500])
            ->assertJsonFragment(['slug' => 'comida-por-kg-somente-carne', 'menu_rule_code' => 'counter_weight_meat_only', 'base_price_cents' => 7000])
            ->json('data');

        $bySlug = collect($products)->keyBy('slug');

        $this->assertSame('unit', $bySlug['self-service']['pricing_mode']);
        $this->assertSame('weight', $bySlug['comida-por-kg-comum']['pricing_mode']);
        $this->assertSame('grams', $bySlug['comida-por-kg-comum']['weight_unit']);
        $this->assertSame([
            'code' => 'extra_beef',
            'name' => 'Bife adicional',
            'price_cents' => 700,
            'max_quantity' => 1,
        ], $bySlug['self-service']['additions'][0]);
        $this->assertSame($bySlug['self-service']['additions'], $bySlug['comida-por-kg-comum']['additions']);
        $this->assertSame([], $bySlug[$visible->slug]['additions']);
    }

    public function test_human_counter_sale_uses_catalog_price_records_audited_payment_and_skips_delivery_flow(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'Doce de leite', 350);

        $response = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price_cents' => 1,
                ]],
                'payment_method' => Payment::METHOD_CASH,
            ])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'Balcao')
            ->assertJsonPath('data.status', 'finalizado')
            ->assertJsonPath('data.paymentStatus', 'pago')
            ->assertJsonPath('data.paymentMethod', 'dinheiro')
            ->assertJsonPath('data.total', 7)
            ->json('data');

        $order = Order::query()->with(['items', 'payments', 'statusHistories'])->findOrFail($response['id']);

        $this->assertNull($order->payer_customer_id);
        $this->assertNull($order->conversation_id);
        $this->assertSame(Order::CHANNEL_COUNTER, $order->origin_channel);
        $this->assertSame(Order::CHANNEL_COUNTER, $order->entry_mode);
        $this->assertSame(Order::FULFILLMENT_COUNTER, $order->fulfillment_type);
        $this->assertSame(Order::STATUS_FINISHED, $order->status);
        $this->assertFalse((bool) $order->print_required);
        $this->assertSame(700, $order->total_cents);
        $this->assertSame(350, $order->items->sole()->unit_price_cents);
        $this->assertSame(700, $order->items->sole()->total_price_cents);
        $this->assertSame(Payment::METHOD_CASH, $order->payments->sole()->method);
        $this->assertSame(Payment::STATUS_CONFIRMED, $order->payments->sole()->status);
        $this->assertSame($user->id, $order->payments->sole()->confirmed_by_user_id);
        $this->assertTrue($order->statusHistories->contains('reason', 'counter_sale_completed'));

        $snapshot = app(OperationalCrmPresenter::class)->snapshot($company, $user);
        $entry = collect($snapshot['financeEntries'])->firstWhere('orderId', (string) $order->id);

        $this->assertNotNull($entry);
        $this->assertSame('Venda de balcão '.$order->code, $entry['label']);
        $this->assertSame('pago', $entry['status']);
        $this->assertEquals(7, $entry['receivedAmount']);
        $this->assertFalse(collect($snapshot['deliveries'])->contains('id', (string) $order->id));
        $this->assertFalse(app(CopilotProductEligibility::class)->isEligible($product));
    }

    public function test_counter_sale_optionally_links_an_existing_tenant_customer_without_changing_pricing(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'Água mineral', 500);
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Murilo Ramos',
            'phone' => '62996191921',
        ]);
        $otherCompany = Company::query()->create(['name' => 'Empresa externa', 'slug' => 'empresa-externa']);
        $otherCustomer = Customer::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Cliente externo',
        ]);

        $sale = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payment_method' => Payment::METHOD_PIX,
                'customer_id' => $customer->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', (string) $customer->id)
            ->assertJsonPath('data.customer.name', 'Murilo Ramos')
            ->assertJsonPath('data.total', 10)
            ->json('data');

        $order = Order::query()->with('payments')->findOrFail($sale['id']);
        $this->assertSame($customer->id, $order->payer_customer_id);
        $this->assertSame('Murilo Ramos', $order->customer_name_snapshot);
        $this->assertSame(1000, $order->total_cents);
        $this->assertSame($customer->id, $order->payments->sole()->customer_id);

        $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'customer_id' => $otherCustomer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este cliente não pertence ao restaurante atual.');

        $this->assertSame(1, Order::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, Payment::query()->where('company_id', $company->id)->count());
    }

    public function test_counter_sale_keeps_walk_in_name_in_order_snapshots_without_implicitly_creating_customer(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'Suco natural', 800);

        $sale = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'customer_name' => '  JoÃ£o   da Silva  ',
                'save_customer' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.name', 'JoÃ£o da Silva')
            ->assertJsonPath('data.total', 8)
            ->json('data');

        $order = Order::query()->with('payments')->findOrFail($sale['id']);

        $this->assertNull($order->payer_customer_id);
        $this->assertSame('JoÃ£o da Silva', $order->customer_name_snapshot);
        $this->assertNull($order->customer_phone_snapshot);
        $this->assertSame(0, Customer::query()->where('company_id', $company->id)->count());
        $this->assertSame(800, $order->total_cents);
        $this->assertCount(1, $order->payments);
    }

    public function test_explicit_customer_save_deduplicates_only_by_equivalent_phone_within_tenant(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'CafÃ©', 400);

        $first = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_PIX,
                'customer_name' => 'Ana Souza',
                'customer_phone' => '(62) 9 9999-1111',
                'save_customer' => true,
            ])
            ->assertCreated()
            ->json('data');

        $second = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'customer_name' => 'Nome digitado novamente',
                'customer_phone' => '62999991111',
                'save_customer' => true,
            ])
            ->assertCreated()
            ->json('data');

        $third = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'customer_name' => 'Ana Souza',
                'save_customer' => true,
            ])
            ->assertCreated()
            ->json('data');

        $fourth = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'customer_name' => 'Ana Souza',
                'save_customer' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['customer']['id'], $second['customer']['id']);
        $this->assertNotSame($third['customer']['id'], $fourth['customer']['id']);
        $this->assertSame(3, Customer::query()->where('company_id', $company->id)->count());
        $this->assertSame(4, Payment::query()->where('company_id', $company->id)->count());
        $this->assertSame(1600, Order::query()->where('company_id', $company->id)->sum('total_cents'));
    }

    public function test_counter_sale_supports_multiple_products_and_each_human_payment_method(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $first = $this->counterProduct($company, 'Refrigerante', 550);
        $second = $this->counterProduct($company, 'Suco de laranja', 700);

        foreach ([Payment::METHOD_PIX, Payment::METHOD_DEBIT_CARD] as $paymentMethod) {
            $sale = $this->actingAs($user)
                ->postJson('/api/app/counter-sales', [
                    'items' => [
                        ['product_id' => $first->id, 'quantity' => 1],
                        ['product_id' => $second->id, 'quantity' => 2],
                    ],
                    'payment_method' => $paymentMethod,
                ])
                ->assertCreated()
                ->json('data');

            $order = Order::query()->with(['items', 'payments'])->findOrFail($sale['id']);
            $this->assertSame(1950, $order->total_cents);
            $this->assertSame($paymentMethod, $order->payments->sole()->method);
            $this->assertCount(2, $order->items);
            $this->assertSame(1400, $order->items->firstWhere('product_id', $second->id)->total_price_cents);
        }
    }

    public function test_self_service_is_a_counter_sale_with_an_optional_component_snapshot(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = Product::query()->where('company_id', $company->id)->where('slug', 'self-service')->firstOrFail();

        $sale = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [[
                    'product_id' => $selfService->id,
                    'quantity' => 1,
                    'selected_components' => ['arroz', 'feijão', 'porco', 'salada'],
                ]],
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', 19)
            ->json('data');

        $order = Order::query()->with('items')->findOrFail($sale['id']);

        $this->assertSame(Order::FULFILLMENT_COUNTER, $order->fulfillment_type);
        $this->assertSame(1900, $order->total_cents);
        $this->assertSame(['arroz', 'feijão', 'porco', 'salada'], $order->items->sole()->selected_components);
        $this->assertNull($order->conversation_id);
        $this->assertFalse(app(CopilotProductEligibility::class)->isEligible($selfService));
    }

    public function test_standard_weight_sale_uses_integer_half_up_pricing_and_historical_snapshots(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->catalogProduct($company, 'comida-por-kg-comum');

        $sale = $this->completeSale($user, [[
            'product_id' => $product->id,
            'quantity' => 1,
            'weight_grams' => 540,
            'price_per_kg_cents' => 1,
            'subtotal_cents' => 1,
            'unit_price_cents' => 1,
        ]]);

        $order = Order::query()->with(['items.options', 'payments', 'deliveryQuotes'])->findOrFail($sale['id']);
        $item = $order->items->sole();
        $payment = $order->payments->sole();

        $this->assertSame(540, $item->weight_grams);
        $this->assertSame(5500, $item->price_per_kg_cents);
        $this->assertSame(2970, $item->unit_price_cents);
        $this->assertSame(0, $item->options_total_cents);
        $this->assertSame(2970, $item->total_price_cents);
        $this->assertSame(2970, $order->total_cents);
        $this->assertSame(2970, $payment->amount_cents);
        $this->assertSame(2970, $payment->confirmed_amount_cents);
        $this->assertNull($order->conversation_id);
        $this->assertNull($order->delivery_address_id);
        $this->assertCount(0, $order->deliveryQuotes);
    }

    public function test_meat_only_weight_sale_calculates_the_canonical_total(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->catalogProduct($company, 'comida-por-kg-somente-carne');

        $sale = $this->completeSale($user, [[
            'product_id' => $product->id,
            'quantity' => 1,
            'weight_grams' => 450,
        ]]);

        $order = Order::query()->with(['items', 'payments'])->findOrFail($sale['id']);

        $this->assertSame(3150, $order->total_cents);
        $this->assertSame(7000, $order->items->sole()->price_per_kg_cents);
        $this->assertSame(3150, $order->items->sole()->unit_price_cents);
        $this->assertSame($order->total_cents, $order->payments->sole()->confirmed_amount_cents);
    }

    public function test_weight_sale_with_beef_uses_the_canonical_rule_and_separate_option(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->catalogProduct($company, 'comida-por-kg-comum');

        $sale = $this->completeSale($user, [[
            'product_id' => $product->id,
            'quantity' => 1,
            'weight_grams' => 540,
            'additions' => [['code' => 'extra_beef', 'quantity' => 1]],
        ]]);

        $order = Order::query()->with(['items.options', 'payments'])->findOrFail($sale['id']);
        $item = $order->items->sole();
        $beef = $item->options->sole();

        $this->assertSame(2970, $item->unit_price_cents);
        $this->assertSame(700, $item->options_total_cents);
        $this->assertSame(3670, $item->total_price_cents);
        $this->assertSame('bife_adicional', $beef->group_code);
        $this->assertSame('Bife adicional', $beef->name);
        $this->assertSame(1, $beef->quantity);
        $this->assertSame(700, $beef->price_delta_cents);
        $this->assertSame(700, $beef->total_price_cents);
        $this->assertSame('canonical_bife_adicional', data_get($beef->metadata, 'source'));
        $this->assertSame(3670, $order->total_cents);
        $this->assertSame($order->total_cents, $order->payments->sole()->confirmed_amount_cents);
    }

    public function test_self_service_accepts_canonical_beef_without_arbitrary_price(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = $this->catalogProduct($company, 'self-service');

        $sale = $this->completeSale($user, [[
            'product_id' => $selfService->id,
            'quantity' => 1,
            'additions' => [['code' => 'extra_beef', 'quantity' => 1]],
        ]]);

        $order = Order::query()->with(['items.options', 'payments'])->findOrFail($sale['id']);
        $item = $order->items->sole();

        $this->assertSame(1900, $item->unit_price_cents);
        $this->assertSame(700, $item->options_total_cents);
        $this->assertSame(2600, $item->total_price_cents);
        $this->assertSame(700, $item->options->sole()->total_price_cents);
        $this->assertSame(2600, $order->total_cents);
        $this->assertSame($order->total_cents, $order->payments->sole()->confirmed_amount_cents);
    }

    public function test_weight_sale_rejects_missing_zero_negative_excessive_and_non_integer_weights(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->catalogProduct($company, 'comida-por-kg-comum');
        config()->set('chatbotcrm.counter_sales.max_weight_grams', 10000);

        foreach ([null, 0, -1, 10001, 540.5] as $weight) {
            $item = ['product_id' => $product->id, 'quantity' => 1];
            if ($weight !== null) {
                $item['weight_grams'] = $weight;
            }

            $this->actingAs($user)
                ->postJson('/api/app/counter-sales', [
                    'items' => [$item],
                    'payment_method' => Payment::METHOD_PIX,
                ])
                ->assertUnprocessable();
        }

        $this->assertSame(0, Order::query()->where('company_id', $company->id)->count());
    }

    public function test_unit_product_rejects_an_unexpected_weight_and_still_works_without_it(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'Doce de leite', 350);

        $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'weight_grams' => 540]],
                'payment_method' => Payment::METHOD_CASH,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Peso só pode ser informado para produtos vendidos por kg.');

        $sale = $this->completeSale($user, [['product_id' => $product->id, 'quantity' => 1]]);
        $order = Order::query()->with('items')->findOrFail($sale['id']);

        $this->assertSame(350, $order->total_cents);
        $this->assertNull($order->items->sole()->weight_grams);
        $this->assertNull($order->items->sole()->price_per_kg_cents);
    }

    public function test_weight_sale_preserves_the_historical_rate_after_the_product_changes(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->catalogProduct($company, 'comida-por-kg-comum');
        $sale = $this->completeSale($user, [[
            'product_id' => $product->id,
            'quantity' => 1,
            'weight_grams' => 540,
        ]]);

        $product->update(['base_price_cents' => 6000]);
        $item = Order::query()->findOrFail($sale['id'])->items()->sole();

        $this->assertSame(5500, $item->price_per_kg_cents);
        $this->assertSame(2970, $item->unit_price_cents);
        $this->assertSame(2970, $item->total_price_cents);
    }

    public function test_beef_price_from_the_browser_is_rejected_and_quantity_above_the_limit_is_blocked(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = $this->catalogProduct($company, 'self-service');

        $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [[
                    'product_id' => $selfService->id,
                    'quantity' => 1,
                    'additions' => [['code' => 'extra_beef', 'quantity' => 1, 'price_delta_cents' => 1]],
                ]],
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [[
                    'product_id' => $selfService->id,
                    'quantity' => 1,
                    'additions' => [['code' => 'extra_beef', 'quantity' => 2]],
                ]],
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No máximo um bife adicional pode ser escolhido por item.');

        $this->assertSame(0, Order::query()->where('company_id', $company->id)->count());
    }

    public function test_inconsistent_canonical_beef_prices_block_counter_sale(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = $this->catalogProduct($company, 'self-service');
        $link = ProductGroupComponent::query()
            ->whereHas('group', fn ($query) => $query
                ->where('code', 'bife_adicional')
                ->whereHas('product', fn ($products) => $products->where('menu_rule_code', 'n9_tradicional')))
            ->firstOrFail();
        $link->update(['price_delta_cents' => 800]);

        $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [[
                    'product_id' => $selfService->id,
                    'quantity' => 1,
                    'additions' => [['code' => 'extra_beef', 'quantity' => 1]],
                ]],
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A configuração canônica de bife adicional está inconsistente entre os produtos.');

        $this->assertSame(0, Order::query()->where('company_id', $company->id)->count());
    }

    public function test_counter_sale_rejects_other_company_and_unavailable_products(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $unavailable = $this->counterProduct($company, 'Sem venda hoje', 350, isAvailable: false);
        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $external = $this->counterProduct($otherCompany, 'Produto externo', 350);

        foreach ([$unavailable, $external] as $product) {
            $this->actingAs($user)
                ->postJson('/api/app/counter-sales', [
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                    'payment_method' => Payment::METHOD_PIX,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Este produto não está disponível para venda no Caixa.');
        }

        $this->assertSame(0, Order::query()->where('company_id', $company->id)->count());
    }

    public function test_counter_sale_cancellation_voids_the_payment_and_preserves_history_without_a_refund(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'Água mineral', 300);

        $sale = $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_DEBIT_CARD,
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$sale['id']}/cancel", [
                'reason' => 'erro_no_registro',
                'notes' => 'Cancelamento humano validado.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelado')
            ->assertJsonPath('data.paymentStatus', 'pendente');

        $order = Order::query()->with(['payments', 'statusHistories'])->findOrFail($sale['id']);
        $payment = $order->payments->sole();

        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertSame($user->id, $payment->voided_by_user_id);
        $this->assertSame('erro_no_registro', $payment->void_reason);
        $this->assertTrue($order->statusHistories->contains('reason', 'counter_sale_cancelled'));
        $this->assertSame(1, $order->payments()->count());

        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$sale['id']}/cancel", [
                'reason' => 'cancelamento_repetido',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta venda de balcão já foi cancelada.');

        $this->assertSame(1, $order->refresh()->payments()->count());
    }

    /** @return array{0: Company, 1: User} */
    private function companyAndOperator(): array
    {
        $this->seed([RoleAndPermissionSeeder::class, SolRestaurantStructuredMenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::SUPER_ADMIN);

        return [$company, $user];
    }

    private function counterProduct(Company $company, string $name, int $priceCents, bool $isActive = true, bool $isAvailable = true): Product
    {
        $category = ProductCategory::query()->firstOrCreate(
            ['company_id' => $company->id, 'slug' => 'doces'],
            [
                'name' => 'Doces',
                'category_type' => ProductCategory::TYPE_DOCES,
                'display_order' => 80,
                'is_active' => true,
            ],
        );

        return Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.Str::random(5))->toString(),
            'product_type' => Product::TYPE_COUNTER,
            'base_price_cents' => $priceCents,
            'currency' => 'BRL',
            'is_active' => $isActive,
            'is_available_by_default' => $isAvailable,
            'metadata' => ['counter_sale' => true],
        ]);
    }

    private function catalogProduct(Company $company, string $slug): Product
    {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function completeSale(User $user, array $items): array
    {
        return $this->actingAs($user)
            ->postJson('/api/app/counter-sales', [
                'items' => $items,
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertCreated()
            ->json('data');
    }
}
