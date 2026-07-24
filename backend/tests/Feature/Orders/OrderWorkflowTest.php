<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_draft_orders_receive_daily_sequence_and_history(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Exemplo',
        ]);
        $service = app(OrderWorkflowService::class);
        $date = CarbonImmutable::create(2026, 7, 6);

        $firstOrder = $service->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => $date,
            'origin_channel' => Order::CHANNEL_COUNTER,
            'entry_mode' => Order::CHANNEL_MANUAL,
            'is_manual' => true,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'pickup_person_name' => 'Pessoa Autorizada',
            'pickup_notes' => 'Retirada por terceiro autorizada no atendimento.',
            'general_notes' => 'Pedido criado manualmente em rascunho.',
        ]);

        $secondOrder = $service->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => $date,
            'origin_channel' => Order::CHANNEL_COUNTER,
            'is_manual' => true,
        ]);

        $this->assertSame(1, $firstOrder->daily_sequence);
        $this->assertSame('20260706-0001', $firstOrder->code);
        $this->assertSame(2, $secondOrder->daily_sequence);
        $this->assertSame(Order::STATUS_DRAFT, $firstOrder->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $firstOrder->id,
            'from_status' => null,
            'to_status' => Order::STATUS_DRAFT,
            'reason' => 'order_created',
        ]);
    }

    public function test_daily_sequence_is_scoped_by_company_and_order_date(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $otherCompany = Company::query()->create([
            'name' => 'Outro Restaurante',
            'slug' => 'outro-restaurante',
        ]);
        $service = app(OrderWorkflowService::class);

        $firstOrder = $service->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);
        $secondOrder = $service->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);
        $otherCompanyOrder = $service->createDraft($otherCompany, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);
        $otherDateOrder = $service->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 7)]);

        $this->assertSame(1, $firstOrder->daily_sequence);
        $this->assertSame(2, $secondOrder->daily_sequence);
        $this->assertSame(1, $otherCompanyOrder->daily_sequence);
        $this->assertSame(1, $otherDateOrder->daily_sequence);
    }

    public function test_failed_draft_creation_rolls_back_without_consuming_daily_sequence(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $service = app(OrderWorkflowService::class);
        $date = CarbonImmutable::create(2026, 7, 6);
        $firstOrder = $service->createDraft($company, ['order_date' => $date]);

        try {
            $service->createDraft($company, [
                'order_date' => $date,
                'code' => $firstOrder->code,
            ]);
            $this->fail('Duplicate order code should fail inside the draft transaction.');
        } catch (QueryException) {
            // Expected: the transaction must roll back the attempted order and its sequence.
        }

        $nextOrder = $service->createDraft($company, ['order_date' => $date]);

        $this->assertSame(2, $nextOrder->daily_sequence);
        $this->assertSame(2, Order::query()->where('company_id', $company->id)->whereDate('order_date', $date)->count());
    }

    public function test_daily_sequence_aggregation_is_not_locked_with_for_update(): void
    {
        $this->seed(CompanySeeder::class);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        app(OrderWorkflowService::class)->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);

        $aggregateLocks = array_filter($queries, fn (string $query): bool => str_contains($query, 'max') && str_contains($query, 'daily_sequence') && str_contains($query, 'for update'));

        $this->assertSame([], array_values($aggregateLocks));
    }

    public function test_order_item_keeps_item_notes_beneficiary_options_and_totals(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $product = Product::query()->where('slug', 'n8-casa')->firstOrFail();
        $option = ProductOption::query()->where('slug', 'ovo-frito')->firstOrFail();
        $service = app(OrderWorkflowService::class);
        $order = $service->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);

        $item = $service->addItem($order, $product, [
            'item_notes' => 'Pouco arroz, sem fritura.',
            'beneficiary_name' => 'Pessoa Beneficiaria',
            'beneficiary_notes' => 'Item separado para retirada.',
            'preferences' => ['mais_salada'],
            'restrictions' => ['sem_fritura'],
            'selected_components' => ['salada' => 'vinagrete'],
            'options' => [
                ['product_option_id' => $option->id],
            ],
        ]);

        $order->refresh();

        $this->assertSame(1500, $item->total_price_cents);
        $this->assertSame(1500, $order->total_cents);
        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'beneficiary_name' => 'Pessoa Beneficiaria',
            'item_notes' => 'Pouco arroz, sem fritura.',
        ]);
        $this->assertDatabaseHas('order_item_options', [
            'order_item_id' => $item->id,
            'product_option_id' => $option->id,
            'price_delta_cents' => 200,
            'total_price_cents' => 200,
        ]);
    }

    public function test_order_item_endpoint_persists_real_order_product_options_and_returns_updated_order(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Exemplo',
        ]);
        $product = Product::query()->where('slug', 'n8-casa')->firstOrFail();
        $option = ProductOption::query()->where('slug', 'ovo-frito')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $service = app(OrderWorkflowService::class);
        $order = $service->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $ordersBefore = Order::query()->where('company_id', $company->id)->count();

        $data = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => (string) $product->id,
                'quantity' => 2,
                'item_notes' => 'Separar sem cebola.',
                'beneficiary_name' => 'Pessoa Beneficiaria',
                'options' => [
                    [
                        'product_option_id' => (string) $option->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame((string) $order->id, $data['id']);
        $this->assertCount(1, $data['items']);
        $this->assertSame($ordersBefore, Order::query()->where('company_id', $company->id)->count());
        $this->assertSame('N8 Casa', $data['items'][0]['name']);
        $this->assertSame(2, $data['items'][0]['quantity']);
        $this->assertSame('Pessoa Beneficiaria', $data['items'][0]['beneficiary']);
        $this->assertContains('Ovo frito', $data['items'][0]['additions']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'beneficiary_name' => 'Pessoa Beneficiaria',
            'item_notes' => 'Separar sem cebola.',
        ]);
        $this->assertDatabaseHas('order_item_options', [
            'product_option_id' => $option->id,
            'name' => 'Ovo frito',
        ]);
    }

    public function test_fragmented_order_can_reference_previous_order_without_copying_items(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Recorrente',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => Order::CHANNEL_WHATSAPP,
            'status' => 'open',
            'started_at' => now(),
        ]);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $service = app(OrderWorkflowService::class);

        $previousOrder = $service->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 5),
        ]);
        $service->addItem($previousOrder, $product);

        $currentOrder = $service->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
            'origin_channel' => Order::CHANNEL_WHATSAPP,
            'recurrence_requested' => true,
            'recurring_order_reference_id' => $previousOrder->id,
            'recurrence_note' => 'Cliente pediu igual ontem; exige confirmacao humana.',
        ]);

        $fragment = $service->addFragment($currentOrder, [
            'conversation_id' => $conversation->id,
            'source_channel' => Order::CHANNEL_WHATSAPP,
            'content_summary' => 'Cliente pediu igual ontem; aguardando conferencia.',
            'parsed_payload' => ['recurrence_intent' => true],
        ]);

        $currentOrder->refresh();

        $this->assertTrue($currentOrder->is_fragmented);
        $this->assertTrue($currentOrder->recurrence_requested);
        $this->assertSame($previousOrder->id, $currentOrder->recurring_order_reference_id);
        $this->assertSame(0, $currentOrder->items()->count());
        $this->assertSame($currentOrder->id, $fragment->order_id);
    }

    public function test_status_history_is_recorded_and_printed_order_cannot_be_edited(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $service = app(OrderWorkflowService::class);
        $order = $service->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);

        $service->transitionTo($order, Order::STATUS_AWAITING_CUSTOMER_CONFIRMATION, reason: 'summary_sent');
        $service->transitionTo($order, Order::STATUS_CONFIRMED, reason: 'customer_confirmed');
        $service->transitionTo($order, Order::STATUS_READY_TO_PRINT, reason: 'review_done');
        $printedOrder = $service->transitionTo($order, Order::STATUS_PRINTED, reason: 'ticket_printed');

        $this->assertSame(Order::STATUS_PRINTED, $printedOrder->status);
        $this->assertNotNull($printedOrder->editing_locked_at);
        $this->assertSame(5, $printedOrder->statusHistories()->count());

        $this->expectException(DomainException::class);

        $service->addItem($printedOrder, $product);
    }
}
