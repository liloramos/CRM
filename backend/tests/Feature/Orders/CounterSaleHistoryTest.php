<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Operational\OperationalCrmPresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CounterSaleHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_company_scoped_and_excludes_non_counter_orders(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->counterProduct($company, 'Água mineral', 300);
        $sale = $this->completeSale($user, [['product_id' => $product->id, 'quantity' => 1]], Payment::METHOD_CASH);

        Order::query()->create([
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 900,
            'code' => 'WHA-900',
            'status' => Order::STATUS_FINISHED,
            'origin_channel' => Order::CHANNEL_WHATSAPP,
            'entry_mode' => Order::CHANNEL_WHATSAPP,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'payment_status' => Payment::ORDER_STATUS_PAID,
            'payment_method' => Payment::METHOD_PIX,
            'total_cents' => 9900,
            'amount_paid_cents' => 9900,
        ]);

        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherUser->assignRole(Role::SUPER_ADMIN);
        $otherProduct = $this->counterProduct($otherCompany, 'Produto externo', 500);
        $this->completeSale($otherUser, [['product_id' => $otherProduct->id, 'quantity' => 1]], Payment::METHOD_PIX);

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales')
            ->assertOk()
            ->assertJsonCount(1, 'data.sales')
            ->assertJsonPath('data.sales.0.id', $sale['id'])
            ->assertJsonPath('data.summary.totalSoldCents', 300)
            ->assertJsonPath('data.summary.completedSalesCount', 1);
    }

    public function test_history_summarizes_confirmed_counter_sales_by_payment_and_excludes_cancelled_sales_from_revenue_and_ranking(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $water = $this->counterProduct($company, 'Água mineral', 200);
        $juice = $this->counterProduct($company, 'Suco de laranja', 300);

        $cashSale = $this->completeSale($user, [['product_id' => $water->id, 'quantity' => 2]], Payment::METHOD_CASH);
        $this->completeSale($user, [
            ['product_id' => $water->id, 'quantity' => 1],
            ['product_id' => $juice->id, 'quantity' => 3],
        ], Payment::METHOD_PIX);
        $this->completeSale($user, [['product_id' => $juice->id, 'quantity' => 1]], Payment::METHOD_DEBIT_CARD);

        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$cashSale['id']}/cancel", [
                'reason' => 'erro_no_registro',
                'notes' => 'Cancelamento de teste.',
            ])
            ->assertOk();

        $response = $this->actingAs($user)
            ->getJson('/api/app/counter-sales')
            ->assertOk()
            ->assertJsonPath('data.summary.totalSoldCents', 1400)
            ->assertJsonPath('data.summary.completedSalesCount', 2)
            ->assertJsonPath('data.summary.totalCancelledCents', 400)
            ->assertJsonPath('data.summary.paymentTotals.cash', 0)
            ->assertJsonPath('data.summary.paymentTotals.pix', 1100)
            ->assertJsonPath('data.summary.paymentTotals.card', 300)
            ->assertJsonPath('data.topProducts.0.productName', 'Suco de laranja')
            ->assertJsonPath('data.topProducts.0.quantity', 4)
            ->assertJsonPath('data.topProducts.0.totalCents', 1200)
            ->json('data');

        $this->assertTrue(collect($response['sales'])->contains('status', 'completed'));
        $this->assertTrue(collect($response['sales'])->contains('status', 'cancelled'));

        $snapshot = app(OperationalCrmPresenter::class)->snapshot($company, $user);
        $counterRevenue = collect($snapshot['financeEntries'])
            ->filter(fn (array $entry): bool => str_starts_with($entry['label'], 'Venda de balcão '))
            ->where('status', 'pago')
            ->sum('receivedAmount');

        $this->assertSame(14.0, (float) $counterRevenue);
        $this->assertSame(1400, $response['summary']['totalSoldCents']);

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales?status=cancelled&payment_method=cash')
            ->assertOk()
            ->assertJsonCount(1, 'data.sales')
            ->assertJsonPath('data.sales.0.status', 'cancelled');
    }

    public function test_detail_returns_historical_items_payment_and_company_timezone_without_exposing_other_sales(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $company->setting()->update(['timezone' => 'America/Sao_Paulo']);
        $product = $this->counterProduct($company, 'Bolo de milho', 450);

        $instant = CarbonImmutable::parse('2026-08-24 02:30:00', 'UTC');
        $this->travelTo($instant);

        try {
            $sale = $this->completeSale($user, [['product_id' => $product->id, 'quantity' => 2]], Payment::METHOD_PIX);

            $this->actingAs($user)
                ->getJson('/api/app/counter-sales')
                ->assertOk()
                ->assertJsonPath('data.filters.timezone', 'America/Sao_Paulo')
                ->assertJsonPath('data.filters.dateFrom', '2026-08-23')
                ->assertJsonPath('data.sales.0.timeLabel', '23:30');

            $this->actingAs($user)
                ->getJson("/api/app/counter-sales/{$sale['id']}")
                ->assertOk()
                ->assertJsonPath('data.originLabel', 'Venda de balcão')
                ->assertJsonPath('data.items.0.productName', 'Bolo de milho')
                ->assertJsonPath('data.items.0.quantity', 2)
                ->assertJsonPath('data.items.0.unitPriceCents', 450)
                ->assertJsonPath('data.items.0.subtotalCents', 900)
                ->assertJsonPath('data.payment.methodLabel', 'Pix')
                ->assertJsonPath('data.status', 'completed');
        } finally {
            $this->travelBack();
        }
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

    /** @param list<array{product_id: int, quantity: int}> $items @return array<string, mixed> */
    private function completeSale(User $user, array $items, string $paymentMethod): array
    {
        return $this->actingAs($user)
            ->postJson('/api/app/counter-sales', ['items' => $items, 'payment_method' => $paymentMethod])
            ->assertCreated()
            ->json('data');
    }

    private function counterProduct(Company $company, string $name, int $priceCents): Product
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
            'is_active' => true,
            'is_available_by_default' => true,
            'metadata' => ['counter_sale' => true],
        ]);
    }
}
