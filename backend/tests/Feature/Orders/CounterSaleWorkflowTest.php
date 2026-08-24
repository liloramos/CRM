<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
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

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.base_price_cents', 350);
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
}
