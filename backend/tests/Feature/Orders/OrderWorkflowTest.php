<?php

namespace Tests\Feature\Orders;

use App\Enums\MenuAvailabilityStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DailyComponentAvailability;
use App\Models\MenuComponent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PrintingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
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

    public function test_manual_draft_endpoint_accepts_walk_in_customer_snapshot_without_registered_customer(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);

        $data = $this->actingAs($user)
            ->postJson('/api/app/orders/drafts', [
                'customer_name_snapshot' => 'Cliente Avulso Balcao',
                'customer_phone_snapshot' => '(62) 99999-0101',
                'fulfillment_type' => Order::FULFILLMENT_COUNTER,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Cliente Avulso Balcao', $data['customer']['name']);
        $this->assertSame('(62) 99999-0101', $data['customer']['phoneLabel']);
        $this->assertDatabaseHas('orders', [
            'id' => $data['id'],
            'payer_customer_id' => null,
            'customer_name_snapshot' => 'Cliente Avulso Balcao',
            'customer_phone_snapshot' => '(62) 99999-0101',
        ]);
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

    public function test_order_item_without_special_recipient_serializes_beneficiary_as_null(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente Principal']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'payer_customer_id' => $customer->id,
        ]);

        $data = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => (string) $product->id,
                'quantity' => 1,
                'beneficiary_name' => null,
            ])
            ->assertOk()
            ->json('data');

        $this->assertNull($data['items'][0]['beneficiary']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'beneficiary_name' => null,
        ]);
    }

    public function test_structured_order_item_endpoint_persists_only_selected_configuration_options(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Murilo']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);

        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'beneficiary_name' => 'Murilo',
            'structured_options' => $this->componentChoiceRows($product, [
                'salada_casa' => ['beterraba'],
                'carne' => ['porco'],
            ]),
        ];

        $data = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame((string) $order->id, $data['id']);
        $this->assertCount(6, $data['items'][0]['composition']);
        $this->assertContains('Arroz', $data['items'][0]['composition']);
        $this->assertContains('Mandioca', $data['items'][0]['composition']);
        $this->assertContains('Beterraba', $data['items'][0]['composition']);
        $this->assertContains('Porco', $data['items'][0]['composition']);
        $this->assertNotContains('Repolho com tomate', $data['items'][0]['composition']);
        $this->assertNotContains('Vinagrete', $data['items'][0]['composition']);
        $this->assertSame([], $data['items'][0]['removals']);
        $this->assertSame([], $data['items'][0]['additions']);
        $this->assertSame(6, $order->refresh()->items()->firstOrFail()->options()->count());
        $this->assertDatabaseHas('order_item_options', [
            'name' => 'Porco',
            'group_code' => 'carne',
            'quantity' => 1,
        ]);
    }

    public function test_structured_order_item_persists_removals_from_default_house_composition(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $feijao = $this->menuComponentId($company, 'feijao');
        $macarrao = $this->menuComponentId($company, 'macarrao');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'removed_component_ids' => [$feijao, $macarrao],
                'structured_options' => $this->componentChoiceRows($product, [
                    'salada_casa' => ['beterraba'],
                    'carne' => ['porco'],
                ]),
            ])
            ->assertOk();

        $removals = $this->actingAs($user)
            ->getJson("/api/app/orders/{$order->id}")
            ->assertOk()
            ->json('data.items.0.removals');

        $this->assertCount(2, $removals);
        $this->assertStringStartsWith('Sem ', $removals[0]);
        $this->assertStringStartsWith('Sem ', $removals[1]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_house_marmitas_allow_configured_salad_removals_without_changing_the_price(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $orders = app(OrderWorkflowService::class);
        $n5 = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $n8 = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $mandioca = $this->menuComponentId($company, 'mandioca');

        $scenarios = [
            [$n5, ['removed_group_codes' => ['salada_casa']], ['carne' => ['porco']], 8],
            [$n5, ['removed_component_ids' => [$mandioca]], ['salada_casa' => ['beterraba'], 'carne' => ['frango-ao-molho']], 8],
            [$n5, ['removed_group_codes' => ['salada_casa'], 'removed_component_ids' => [$mandioca]], ['carne' => ['porco']], 8],
            [$n8, ['removed_group_codes' => ['salada']], ['carne' => ['frango-ao-molho']], 13],
        ];

        foreach ($scenarios as [$product, $composition, $choices, $price]) {
            $order = $orders->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);
            $item = $this->actingAs($user)
                ->postJson("/api/app/orders/{$order->id}/items", [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    ...$composition,
                    'structured_options' => $this->componentChoiceRows($product, $choices),
                ])
                ->assertOk()
                ->json('data.items.0');

            $this->assertSame($price, $item['unitPrice']);
            if (array_key_exists('removed_group_codes', $composition)) {
                $this->assertContains('Sem Salada', $item['removals']);
            }
        }
    }

    public function test_structured_order_item_rejects_removal_of_a_group_that_is_not_configured_as_removable(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'removed_group_codes' => ['carne'],
                'structured_options' => $this->componentChoiceRows($product, [
                    'salada_casa' => ['beterraba'],
                    'carne' => ['porco'],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['removed_group_codes']);
    }

    public function test_structured_order_item_rejects_removal_outside_default_composition(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $beterraba = $this->menuComponentId($company, 'beterraba');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'removed_component_ids' => [$beterraba],
                'structured_options' => $this->componentChoiceRows($product, [
                    'salada_casa' => ['beterraba'],
                    'carne' => ['porco'],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['removed_component_ids']);
    }

    public function test_structured_order_item_endpoint_rejects_missing_required_and_excess_choices(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'structured_options' => $this->componentChoiceRows($product, [
                    'salada' => ['beterraba'],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Escolha obrigatoria ausente em Carne.');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'structured_options' => $this->componentChoiceRows($product, [
                    'salada' => ['beterraba', 'cenoura'],
                    'carne' => ['porco'],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Escolhas acima do limite em Escolha uma salada.');
    }

    public function test_structured_order_item_endpoint_rejects_unavailable_component_and_option_from_other_product(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $n5 = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $suco = Product::query()->where('company_id', $company->id)->where('slug', 'suco')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $beterraba = MenuComponent::query()->where('company_id', $company->id)->where('slug', 'beterraba')->firstOrFail();

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $beterraba->id,
            'availability_date' => '2026-07-06',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou no almoco.',
        ]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $n5->id,
                'quantity' => 1,
                'structured_options' => $this->componentChoiceRows($n5, [
                    'salada_casa' => ['beterraba'],
                    'carne' => ['porco'],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Beterraba esta indisponivel hoje.');

        $sucoFlavorLink = $this->componentChoiceRows($suco, ['sabor' => ['goiaba']])[0];

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $n5->id,
                'quantity' => 1,
                'structured_options' => [$sucoFlavorLink],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Uma ou mais escolhas nao pertencem ao produto selecionado.');
    }

    public function test_n8_structured_meat_selection_persists_two_pieces_of_the_same_meat(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'structured_options' => $this->componentChoiceRows($product, [
                    'salada' => ['vinagrete'],
                    'carne' => ['frango-ao-molho'],
                ]),
            ])
            ->assertOk();

        $this->assertDatabaseHas('order_item_options', [
            'order_item_id' => $order->refresh()->items()->firstOrFail()->id,
            'name' => 'Frango ao molho',
            'group_code' => 'carne',
            'quantity' => 2,
        ]);
    }

    public function test_n8_traditional_beef_modes_are_validated_priced_and_persisted(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $porco = $this->menuComponentId($company, 'porco');
        $frango = $this->menuComponentId($company, 'frango-ao-molho');

        $traditional = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango],
                'structured_options' => [],
            ])
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(16, $traditional['unitPrice']);
        $this->assertSame(16, $traditional['totalPrice']);
        $this->assertContains('Porco', $traditional['composition']);
        $this->assertContains('Frango ao molho', $traditional['composition']);

        $beefOnly = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'beef_only',
                'structured_options' => [],
                'unit_price_cents' => 9999,
            ])
            ->assertOk()
            ->json('data.items.1');

        $this->assertSame(20, $beefOnly['unitPrice']);
        $this->assertSame(20, $beefOnly['totalPrice']);
        $this->assertContains('Somente bife', $beefOnly['composition']);

        $withExtraBeef = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 2,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango],
                'additions' => [
                    ['code' => 'extra_beef', 'quantity' => 1],
                ],
                'structured_options' => [],
                'unit_price_cents' => 9999,
            ])
            ->assertOk()
            ->json('data.items.2');

        $this->assertSame(23, $withExtraBeef['unitPrice']);
        $this->assertSame(46, $withExtraBeef['totalPrice']);
        $this->assertContains('Bife adicional - R$ 7,00', $withExtraBeef['additions']);
        $this->assertSame(82, (int) (Order::query()->findOrFail($order->id)->total_cents / 100));
        $this->assertDatabaseHas('order_item_options', [
            'name' => 'Bife adicional',
            'group_code' => 'bife_adicional',
            'price_delta_cents' => 700,
            'total_price_cents' => 0,
        ]);
    }

    public function test_house_marmitas_require_an_explicit_no_meat_choice_when_configured(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, ['order_date' => CarbonImmutable::create(2026, 7, 6)]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'structured_options' => [],
            ])
            ->assertStatus(422);

        $withoutMeat = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'none',
                'structured_options' => [],
            ])
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(8, $withoutMeat['unitPrice']);
        $this->assertContains('Sem carne', $withoutMeat['composition']);
    }

    public function test_n8_beef_modes_reject_invalid_combinations_and_unavailable_meat(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $porco = $this->menuComponentId($company, 'porco');
        $frango = $this->menuComponentId($company, 'frango-ao-molho');
        $bife = $this->menuComponentId($company, 'bife');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'beef_only',
                'traditional_meat_component_ids' => [$porco],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Somente bife substitui as carnes tradicionais.');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'beef_only',
                'additions' => [
                    ['code' => 'extra_beef', 'quantity' => 1],
                ],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bife adicional nao pode ser combinado com somente bife.');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco],
                'structured_options' => [],
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Escolha ao menos 1 carne tradicional.');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango, $bife],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Escolha apenas carnes tradicionais validas do cardapio.');

        $withoutMeat = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'none',
                'structured_options' => [],
            ])
            ->assertOk()
            ->json('data.items.1');

        $this->assertSame(16, $withoutMeat['unitPrice']);
        $this->assertContains('Sem carne', $withoutMeat['composition']);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'none',
                'traditional_meat_component_ids' => [$porco],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sem carne nao pode ser combinado com carnes tradicionais.');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'none',
                'additions' => [
                    ['code' => 'extra_beef', 'quantity' => 1],
                ],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sem carne nao pode ser combinado com bife adicional.');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango],
                'additions' => [
                    ['code' => 'extra_beef', 'quantity' => 2],
                ],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No maximo um bife adicional pode ser escolhido.');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $porco,
            'availability_date' => '2026-07-06',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou no almoco.',
        ]);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango],
                'structured_options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Uma das carnes escolhidas nao esta disponivel hoje.');
    }

    public function test_n9_beef_modes_are_priced_and_serialized(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n9-tradicional')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $porco = $this->menuComponentId($company, 'porco');
        $frango = $this->menuComponentId($company, 'frango-ao-molho');

        $traditional = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango],
                'structured_options' => [],
            ])
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(19, $traditional['unitPrice']);

        $beefOnly = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 1,
                'meat_mode' => 'beef_only',
                'structured_options' => [],
            ])
            ->assertOk()
            ->json('data.items.1');

        $this->assertSame(23, $beefOnly['unitPrice']);
        $this->assertContains('Somente bife', $beefOnly['composition']);

        $withExtraBeef = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", [
                'product_id' => $product->id,
                'quantity' => 2,
                'meat_mode' => 'traditional',
                'traditional_meat_component_ids' => [$porco, $frango],
                'additions' => [
                    ['code' => 'extra_beef', 'quantity' => 1],
                ],
                'structured_options' => [],
            ])
            ->assertOk()
            ->json('data.items.2');

        $this->assertSame(26, $withExtraBeef['unitPrice']);
        $this->assertSame(52, $withExtraBeef['totalPrice']);
        $this->assertContains('Bife adicional - R$ 7,00', $withExtraBeef['additions']);
    }

    public function test_manual_orders_keep_customer_beneficiary_items_and_printing_separate(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $murilo = Customer::query()->create(['company_id' => $company->id, 'name' => 'Murilo']);
        $larissa = Customer::query()->create(['company_id' => $company->id, 'name' => 'Larissa']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);

        $muriloOrder = $orders->createDraft($company, ['payer_customer_id' => $murilo->id]);
        $larissaOrder = $orders->createDraft($company, ['payer_customer_id' => $larissa->id]);
        $sameCustomerSecondOrder = $orders->createDraft($company, ['payer_customer_id' => $murilo->id]);

        $orders->addItem($muriloOrder, $product, ['beneficiary_name' => 'Murilo', 'item_notes' => 'Sem cebola.']);
        $orders->addItem($larissaOrder, $product, ['beneficiary_name' => 'Larissa', 'item_notes' => 'Com vinagrete.']);
        $orders->addItem($sameCustomerSecondOrder, $product, ['beneficiary_name' => 'Murilo segundo pedido']);

        $muriloHtml = app(PrintWorkflowService::class)->generateTicket($muriloOrder->refresh())->html_content;
        $larissaHtml = app(PrintWorkflowService::class)->generateTicket($larissaOrder->refresh())->html_content;

        $this->assertNotSame($muriloOrder->id, $larissaOrder->id);
        $this->assertNotSame($muriloOrder->id, $sameCustomerSecondOrder->id);
        $this->assertSame(1, $muriloOrder->items()->count());
        $this->assertSame(1, $larissaOrder->items()->count());
        $this->assertStringContainsString('Murilo', $muriloHtml);
        $this->assertStringContainsString('Sem cebola.', $muriloHtml);
        $this->assertStringNotContainsString('Com vinagrete.', $muriloHtml);
        $this->assertStringContainsString('Larissa', $larissaHtml);
        $this->assertStringContainsString('Com vinagrete.', $larissaHtml);
        $this->assertStringNotContainsString('Sem cebola.', $larissaHtml);
    }

    public function test_order_status_cancel_and_payment_endpoints_persist_real_state(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ATENDENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company);
        $orders->addItem($order, $product);

        $this->actingAs($user)
            ->patchJson("/api/app/orders/{$order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
                'reason' => 'cliente_confirmou',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'aguardando_pagamento');

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                'method' => Payment::METHOD_PIX,
                'amount_cents' => 800,
                'notes' => 'Pagamento conferido no caixa.',
            ])
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', 'pago');

        $order->refresh();
        $this->assertSame(Order::STATUS_PAYMENT_CONFIRMED, $order->status);
        $this->assertSame(Payment::ORDER_STATUS_PAID, $order->payment_status);
        $this->assertSame(800, $order->amount_paid_cents);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/cancel", [
                'reason' => 'cliente_desistiu',
                'notes' => 'Cancelado pela interface operacional.',
            ])
            ->assertStatus(422);

        app(PaymentWorkflowService::class)->voidLatestConfirmedPayment(
            $order,
            $user,
            'Pagamento confirmado por engano.',
        );

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/cancel", [
                'reason' => 'cliente_desistiu',
                'notes' => 'Cancelado pela interface operacional.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelado');

        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(1, $order->items()->count());

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                'method' => Payment::METHOD_PIX,
                'amount_cents' => 800,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nao e possivel confirmar pagamento em pedido cancelado.');

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_order_status_endpoint_rejects_invalid_transition_and_payment_confirmation_is_idempotent(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ATENDENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company);
        $orders->addItem($order, $product);

        $this->actingAs($user)
            ->patchJson("/api/app/orders/{$order->id}/status", [
                'status' => Order::STATUS_FINISHED,
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                'method' => Payment::METHOD_CASH,
                'amount_cents' => 800,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                'method' => Payment::METHOD_CASH,
                'amount_cents' => 800,
            ])
            ->assertOk();

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(800, $order->refresh()->amount_paid_cents);
    }

    public function test_order_state_machine_still_rejects_same_state_transitions(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company);
        $orders->transitionTo($order, Order::STATUS_AWAITING_PAYMENT, $user, 'payment_requested');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Order cannot transition from [awaiting_payment] to [awaiting_payment].');

        $orders->transitionTo($order->refresh(), Order::STATUS_AWAITING_PAYMENT, $user, 'duplicate_transition');
    }

    public function test_order_payment_confirmation_reuses_the_existing_payment_proof_review(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $payments = app(PaymentWorkflowService::class);
        $order = $orders->createDraft($company);
        $orders->addItem($order, $product);
        $order->forceFill(['status' => Order::STATUS_AWAITING_PAYMENT])->save();

        $payment = $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 800,
        ]);
        $proof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 901],
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($user)
                ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                    'method' => Payment::METHOD_PIX,
                    'amount_cents' => 800,
                ])
                ->assertOk()
                ->assertJsonPath('data.paymentStatus', 'pago');
        }

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);
        $this->assertSame($user->id, $payment->confirmed_by_user_id);
        $this->assertSame($payment->id, $proof->refresh()->payment_id);
        $this->assertSame(PaymentProof::STATUS_RECEIVED, $proof->status);
    }

    public function test_manual_payment_confirmation_preserves_ready_to_print_operational_status(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, ['customer_name_snapshot' => 'Pagamento apos impressao']);
        $orders->addItem($order, $product);
        $orders->transitionTo($order->refresh(), Order::STATUS_READY_TO_PRINT, $user, 'ticket_ready');

        foreach ([1, 2] as $attempt) {
            $this->actingAs($user)
                ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                    'method' => Payment::METHOD_PIX,
                    'amount_cents' => 800,
                ])
                ->assertOk()
                ->assertJsonPath('data.backendStatus', Order::STATUS_READY_TO_PRINT)
                ->assertJsonPath('data.paymentStatus', 'pago');
        }

        $order->refresh();
        $payment = $order->payments()->sole();
        $this->assertSame(Order::STATUS_READY_TO_PRINT, $order->status);
        $this->assertSame(Payment::ORDER_STATUS_PAID, $order->payment_status);
        $this->assertSame(800, $order->amount_paid_cents);
        $this->assertSame(0, $order->amount_due_cents);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertSame($user->id, $payment->confirmed_by_user_id);
        $this->assertSame(1, $order->statusHistories()->where('to_status', Order::STATUS_READY_TO_PRINT)->count());
        $this->assertSame(0, $order->statusHistories()->where('to_status', Order::STATUS_PAYMENT_CONFIRMED)->count());

        $financeEntry = collect($this->actingAs($user)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.financeEntries'))
            ->firstWhere('orderId', (string) $order->id);
        $this->assertSame('pago', $financeEntry['status']);
        $this->assertEquals(8, $financeEntry['receivedAmount']);
        $this->assertEquals(0, $financeEntry['pendingAmount']);
    }

    public function test_operational_status_changes_do_not_mutate_confirmed_payments(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $payments = app(PaymentWorkflowService::class);
        $order = $orders->createDraft($company);
        $orders->addItem($order, $product);
        $payment = $payments->confirmOrderPayment($order, $user, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 800,
        ]);
        $paymentBefore = $payment->refresh()->only([
            'id',
            'status',
            'amount_cents',
            'confirmed_amount_cents',
            'confirmed_by_user_id',
        ]);

        foreach ([
            Order::STATUS_READY_TO_PRINT,
            Order::STATUS_AWAITING_PAYMENT,
            Order::STATUS_PAYMENT_CONFIRMED,
            Order::STATUS_READY_TO_PRINT,
        ] as $status) {
            $orders->transitionTo($order->refresh(), $status, $user, 'manual_status_change');
        }

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame($paymentBefore, $payment->refresh()->only([
            'id',
            'status',
            'amount_cents',
            'confirmed_amount_cents',
            'confirmed_by_user_id',
        ]));
        $this->assertSame(800, $order->refresh()->amount_paid_cents);
    }

    public function test_empty_draft_order_can_be_deleted_safely(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $order = app(OrderWorkflowService::class)->createDraft($company);

        $this->actingAs($user)
            ->deleteJson("/api/app/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', (string) $order->id);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('order_status_histories', ['order_id' => $order->id]);
    }

    public function test_cleanup_capabilities_default_to_safe_values_and_use_config(): void
    {
        config(['chatbotcrm.orders.allow_destructive_test_cleanup' => false]);
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $operator = User::factory()->create(['company_id' => $company->id]);
        $operator->assignRole(Role::ATENDENTE);
        $administrator = User::factory()->create(['company_id' => $company->id]);
        $administrator->assignRole(Role::ADMIN_GERENTE);

        $data = $this->actingAs($operator)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertFalse(config('chatbotcrm.orders.allow_destructive_test_cleanup'));
        $this->assertFalse($data['can_permanently_delete_orders']);
        $this->assertFalse($data['can_run_destructive_test_cleanup']);

        $administratorData = $this->actingAs($administrator)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertTrue($administratorData['can_permanently_delete_orders']);

        config(['chatbotcrm.orders.allow_destructive_test_cleanup' => true]);

        $data = $this->actingAs($operator)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertFalse($data['can_permanently_delete_orders']);
        $this->assertTrue($data['can_run_destructive_test_cleanup']);
    }

    public function test_finance_snapshot_uses_canonical_order_deletion_eligibility(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $administrator = User::factory()->create(['company_id' => $company->id]);
        $administrator->assignRole(Role::ADMIN_GERENTE);
        $operator = User::factory()->create(['company_id' => $company->id]);
        $operator->assignRole(Role::ATENDENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $payments = app(PaymentWorkflowService::class);

        $eligibleOrder = $orders->createDraft($company, ['customer_name_snapshot' => 'Financeiro elegivel']);
        $orders->transitionTo($eligibleOrder, Order::STATUS_CANCELLED, $administrator, 'pedido_cancelado');

        $confirmedOrder = $orders->createDraft($company, ['customer_name_snapshot' => 'Financeiro confirmado']);
        $orders->addItem($confirmedOrder, $product);
        $confirmedPayment = $payments->confirmOrderPayment($confirmedOrder, $administrator, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 800,
        ]);
        $orders->transitionTo($confirmedOrder->refresh(), Order::STATUS_CANCELLED, $administrator, 'pedido_cancelado');

        $reviewOrder = $orders->createDraft($company, ['customer_name_snapshot' => 'Financeiro em revisao']);
        $orders->addItem($reviewOrder, $product);
        $reviewPayment = $payments->recordPayment($reviewOrder, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 800,
        ]);
        $reviewProof = $payments->attachProof($reviewPayment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 901],
        ]);
        $orders->transitionTo($reviewOrder->refresh(), Order::STATUS_CANCELLED, $administrator, 'pedido_cancelado');

        $activeOrder = $orders->createDraft($company, ['customer_name_snapshot' => 'Financeiro ativo']);

        $entries = collect($this->actingAs($administrator)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.financeEntries'))
            ->keyBy('orderId');

        $this->assertTrue($entries[(string) $eligibleOrder->id]['permanentDeletion']['eligible']);
        $this->assertSame([], $entries[(string) $eligibleOrder->id]['permanentDeletion']['reasons']);
        $this->assertFalse($entries[(string) $confirmedOrder->id]['permanentDeletion']['eligible']);
        $this->assertContains('payment_confirmed', $entries[(string) $confirmedOrder->id]['permanentDeletion']['reasons']);
        $this->assertFalse($entries[(string) $reviewOrder->id]['permanentDeletion']['eligible']);
        $this->assertContains('payment_review_pending', $entries[(string) $reviewOrder->id]['permanentDeletion']['reasons']);
        $this->assertNull($entries[(string) $activeOrder->id]['permanentDeletion']);

        $operatorEntries = collect($this->actingAs($operator)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.financeEntries'))
            ->keyBy('orderId');

        $this->assertNull($operatorEntries[(string) $eligibleOrder->id]['permanentDeletion']);

        $this->actingAs($operator)
            ->deleteJson("/api/app/orders/{$eligibleOrder->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $eligibleOrder->id]);

        $this->actingAs($administrator)
            ->deleteJson("/api/app/orders/{$eligibleOrder->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $refreshedEntries = collect($this->actingAs($administrator)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->json('data.financeEntries'))
            ->keyBy('orderId');

        $this->assertFalse($refreshedEntries->has((string) $eligibleOrder->id));
        $this->assertTrue($refreshedEntries->has((string) $confirmedOrder->id));
        $this->assertTrue($refreshedEntries->has((string) $reviewOrder->id));
        $this->assertDatabaseHas('payments', ['id' => $confirmedPayment->id, 'status' => Payment::STATUS_CONFIRMED]);
        $this->assertDatabaseHas('payments', ['id' => $reviewPayment->id, 'status' => Payment::STATUS_PROOF_RECEIVED]);
        $this->assertDatabaseHas('payment_proofs', ['id' => $reviewProof->id]);
    }

    public function test_operational_permanent_delete_requires_permission_and_confirmation_but_not_test_flag(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $userWithoutPermission = User::factory()->create(['company_id' => $company->id]);
        $userWithoutPermission->assignRole(Role::ATENDENTE);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $unauthorizedOrder = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Sem permissao']);
        $wrongConfirmationOrder = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Confirmacao errada']);
        $eligibleOrder = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Elegivel']);
        app(OrderWorkflowService::class)->transitionTo($eligibleOrder, Order::STATUS_CANCELLED, $manager, 'pedido_cancelado');

        $this->actingAs($userWithoutPermission)
            ->deleteJson("/api/app/orders/{$unauthorizedOrder->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$wrongConfirmationOrder->id}/permanent", [
                'confirmation' => 'ERRADO',
            ])
            ->assertStatus(422);

        $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$eligibleOrder->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseHas('orders', ['id' => $unauthorizedOrder->id]);
        $this->assertDatabaseHas('orders', ['id' => $wrongConfirmationOrder->id]);
        $this->assertDatabaseMissing('orders', ['id' => $eligibleOrder->id]);
    }

    public function test_destructive_test_cleanup_is_blocked_when_flag_is_false(): void
    {
        config(['chatbotcrm.orders.allow_destructive_test_cleanup' => false]);
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Teste bloqueado']);

        $this->actingAs($manager)
            ->postJson('/api/app/orders/test-cleanup', [
                'order_ids' => [$order->id],
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A limpeza ampla de registros de teste esta desativada neste ambiente.');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_destructive_test_cleanup_is_blocked_in_production_even_when_flag_is_true(): void
    {
        config(['app.env' => 'production']);
        config(['chatbotcrm.orders.allow_destructive_test_cleanup' => true]);
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ATENDENTE);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Teste producao']);

        $this->actingAs($manager)
            ->postJson('/api/app/orders/test-cleanup', [
                'order_ids' => [$order->id],
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A limpeza ampla de registros de teste nao esta disponivel neste ambiente.');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_cancelled_order_without_operational_links_can_be_deleted_permanently(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Cancelado elegivel']);

        app(OrderWorkflowService::class)->transitionTo($order, Order::STATUS_CANCELLED, $manager, 'teste_cancelado');

        $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_administrator_can_delete_cancelled_order_with_only_voided_payment_history(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $administrator = User::factory()->create(['company_id' => $company->id]);
        $administrator->assignRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente preservado']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $payments = app(PaymentWorkflowService::class);
        $order = $orders->createDraft($company, ['payer_customer_id' => $customer->id]);
        $orders->addItem($order, $product);
        $payment = $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 800,
        ]);
        $firstProof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 801],
        ]);
        $secondProof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 802],
        ]);
        $payments->confirmPayment($payment, $administrator);
        $payments->voidLatestConfirmedPayment($order, $administrator, 'Confirmacao anulada antes da exclusao.');
        $orders->transitionTo($order->refresh(), Order::STATUS_CANCELLED, $administrator, 'pedido_cancelado');

        $this->assertSame(Payment::STATUS_CANCELLED, $payment->refresh()->status);
        $this->assertSame(0, $order->refresh()->amount_paid_cents);
        $this->assertSame(Payment::ORDER_STATUS_UNPAID, $order->payment_status);

        $this->actingAs($administrator)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertDatabaseMissing('payment_proofs', ['id' => $firstProof->id]);
        $this->assertDatabaseMissing('payment_proofs', ['id' => $secondProof->id]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_status_histories', ['order_id' => $order->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_permanent_delete_allows_cancelled_financially_zero_order_with_residual_fulfillment_state(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $administrator = User::factory()->create(['company_id' => $company->id]);
        $administrator->assignRole(Role::ADMIN_GERENTE);
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company);
        $order->forceFill([
            'status' => Order::STATUS_CANCELLED,
            'payment_status' => Payment::ORDER_STATUS_UNPAID,
            'amount_paid_cents' => 0,
            'fulfillment_status' => Order::FULFILLMENT_STATUS_DELIVERY_OUT,
            'delivery_status' => Order::DELIVERY_STATUS_OUT_FOR_DELIVERY,
            'cancelled_at' => now(),
        ])->save();

        $this->actingAs($administrator)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", ['confirmation' => 'EXCLUIR'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_permanent_delete_blocks_cancelled_order_with_payment_review_pending(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $administrator = User::factory()->create(['company_id' => $company->id]);
        $administrator->assignRole(Role::ADMIN_GERENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $payments = app(PaymentWorkflowService::class);
        $order = $orders->createDraft($company);
        $orders->addItem($order, $product);
        $payment = $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 800,
        ]);
        $proof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 803],
        ]);
        $orders->transitionTo($order->refresh(), Order::STATUS_CANCELLED, $administrator, 'pedido_cancelado');

        $response = $this->actingAs($administrator)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->json();

        $this->assertContains('payment_review_pending', $response['reasons']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => Payment::STATUS_PROOF_RECEIVED]);
        $this->assertDatabaseHas('payment_proofs', ['id' => $proof->id]);
    }

    public function test_permanent_delete_blocks_cancelled_order_with_customer_credit_movement(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $administrator = User::factory()->create(['company_id' => $company->id]);
        $administrator->assignRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente com credito']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, ['payer_customer_id' => $customer->id]);
        $orders->addItem($order, $product);
        $payments = app(PaymentWorkflowService::class);
        $payments->confirmOrderPayment($order, $administrator, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 1000,
            'overpayment_action' => Payment::OVERPAYMENT_KEEP_AS_CREDIT,
        ]);
        $payments->voidLatestConfirmedPayment($order, $administrator, 'Confirmacao anulada com credito ainda ativo.');
        $orders->transitionTo($order->refresh(), Order::STATUS_CANCELLED, $administrator, 'pedido_cancelado');

        $response = $this->actingAs($administrator)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->json();

        $this->assertContains('financial_movement', $response['reasons']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(200, $customer->refresh()->credit_balance_cents);
    }

    public function test_destructive_test_cleanup_deletes_selected_orders_without_clients_or_other_companies(): void
    {
        config(['chatbotcrm.orders.allow_destructive_test_cleanup' => true]);
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ATENDENTE);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente Preservado']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $firstOrder = $orders->createDraft($company, ['payer_customer_id' => $customer->id]);
        $secondOrder = $orders->createDraft($company, ['customer_name_snapshot' => 'Avulso limpeza']);
        $otherOrder = $orders->createDraft($otherCompany, ['customer_name_snapshot' => 'Outra empresa']);

        $orders->addItem($firstOrder, $product);
        app(PrintWorkflowService::class)->generateTicket($firstOrder->refresh(), $manager);

        $this->actingAs($manager)
            ->postJson('/api/app/orders/test-cleanup', [
                'order_ids' => [$firstOrder->id, $secondOrder->id],
                'confirmation' => 'EXCLUIR',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertDatabaseMissing('orders', ['id' => $firstOrder->id]);
        $this->assertDatabaseMissing('orders', ['id' => $secondOrder->id]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $firstOrder->id]);
        $this->assertDatabaseMissing('print_jobs', ['order_id' => $firstOrder->id]);
        $this->assertDatabaseHas('orders', ['id' => $otherOrder->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_destructive_test_cleanup_rolls_back_when_selection_crosses_company(): void
    {
        config(['chatbotcrm.orders.allow_destructive_test_cleanup' => true]);
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ATENDENTE);
        $ownOrder = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Pedido proprio']);
        $otherOrder = app(OrderWorkflowService::class)->createDraft($otherCompany, ['customer_name_snapshot' => 'Pedido externo']);

        $this->actingAs($manager)
            ->postJson('/api/app/orders/test-cleanup', [
                'order_ids' => [$ownOrder->id, $otherOrder->id],
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $ownOrder->id]);
        $this->assertDatabaseHas('orders', ['id' => $otherOrder->id]);
    }

    public function test_paid_order_cannot_be_deleted_permanently_and_returns_structured_reasons(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Pago bloqueado']);
        app(OrderWorkflowService::class)->addItem($order, $product);

        $this->actingAs($manager)
            ->postJson("/api/app/orders/{$order->id}/payments/confirm", [
                'method' => Payment::METHOD_PIX,
                'amount_cents' => 800,
            ])
            ->assertOk();
        app(OrderWorkflowService::class)->transitionTo($order->refresh(), Order::STATUS_CANCELLED, $manager, 'pedido_cancelado');

        $response = $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'order_not_eligible_for_permanent_deletion')
            ->assertJsonPath('message', 'Este pedido nao atende aos criterios de exclusao administrativa.')
            ->json();

        $this->assertContains('payment_confirmed', $response['reasons']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_printed_order_cannot_be_deleted_permanently(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $order = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Impresso bloqueado']);
        app(OrderWorkflowService::class)->addItem($order, $product);
        $printJob = app(PrintWorkflowService::class)->generateTicket($order->refresh(), $manager);
        app(PrintWorkflowService::class)->markPrinting($printJob, $manager);
        app(PrintWorkflowService::class)->markPrinted($printJob, $manager);

        $response = $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->json();

        $this->assertContains('print_confirmed', $response['reasons']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('print_jobs', ['order_id' => $order->id]);
    }

    public function test_order_in_preparation_cannot_be_deleted_permanently(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, ['customer_name_snapshot' => 'Preparo bloqueado']);
        $orders->addItem($order, $product);
        $printJob = app(PrintWorkflowService::class)->generateTicket($order->refresh(), $manager);
        app(PrintWorkflowService::class)->markPrinting($printJob, $manager);
        app(PrintWorkflowService::class)->markPrinted($printJob, $manager);

        $response = $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$order->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->json();

        $this->assertContains('status_not_eligible', $response['reasons']);
        $this->assertContains('preparation_started', $response['reasons']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_permanent_delete_blocks_other_company_order_without_leaking_data(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante-delete']);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $otherOrder = app(OrderWorkflowService::class)->createDraft($otherCompany, ['customer_name_snapshot' => 'Pedido externo']);

        $this->actingAs($manager)
            ->deleteJson("/api/app/orders/{$otherOrder->id}/permanent", [
                'confirmation' => 'EXCLUIR',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $otherOrder->id]);
    }

    public function test_bulk_permanent_delete_reports_eligible_and_blocked_orders_without_partial_delete(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $eligibleOrder = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Elegivel lote']);
        $blockedOrder = app(OrderWorkflowService::class)->createDraft($company, ['customer_name_snapshot' => 'Bloqueado lote']);
        app(OrderWorkflowService::class)->transitionTo($eligibleOrder, Order::STATUS_CANCELLED, $manager, 'pedido_cancelado');

        app(OrderWorkflowService::class)->addItem($blockedOrder, $product);
        $this->actingAs($manager)
            ->postJson("/api/app/orders/{$blockedOrder->id}/payments/confirm", [
                'method' => Payment::METHOD_PIX,
                'amount_cents' => 800,
            ])
            ->assertOk();
        app(OrderWorkflowService::class)->transitionTo($blockedOrder->refresh(), Order::STATUS_CANCELLED, $manager, 'pedido_cancelado');

        $data = $this->actingAs($manager)
            ->postJson('/api/app/orders/permanent-deletion', [
                'order_ids' => [$eligibleOrder->id, $blockedOrder->id],
                'confirmation' => 'EXCLUIR',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'orders_not_eligible_for_permanent_deletion')
            ->json();

        $this->assertSame((string) $eligibleOrder->id, $data['eligible'][0]['order_id']);
        $this->assertSame((string) $blockedOrder->id, $data['blocked'][0]['order_id']);
        $this->assertContains('payment_confirmed', $data['blocked'][0]['reasons']);
        $this->assertDatabaseHas('orders', ['id' => $eligibleOrder->id]);
        $this->assertDatabaseHas('orders', ['id' => $blockedOrder->id]);
    }

    public function test_non_empty_or_cancelled_order_cannot_be_deleted_physically(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $orderWithItem = $orders->createDraft($company);
        $cancelledOrder = $orders->createDraft($company);

        $orders->addItem($orderWithItem, $product);
        $orders->transitionTo($cancelledOrder, Order::STATUS_CANCELLED, $user, 'cliente_desistiu');

        $this->actingAs($user)
            ->deleteJson("/api/app/orders/{$orderWithItem->id}")
            ->assertStatus(422);

        $this->actingAs($user)
            ->deleteJson("/api/app/orders/{$cancelledOrder->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $orderWithItem->id]);
        $this->assertDatabaseHas('orders', ['id' => $cancelledOrder->id, 'status' => Order::STATUS_CANCELLED]);
        $this->assertSame(1, $orderWithItem->refresh()->items()->count());
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

    /**
     * @param  array<string, list<string>>  $choices
     * @return list<array{component_link_id: int}>
     */
    private function componentChoiceRows(Product $product, array $choices): array
    {
        $rows = [];

        foreach ($choices as $groupCode => $componentSlugs) {
            $group = ProductOptionGroup::query()
                ->where('product_id', $product->id)
                ->where('code', $groupCode)
                ->firstOrFail();

            foreach ($componentSlugs as $componentSlug) {
                $component = MenuComponent::query()
                    ->where('company_id', $product->company_id)
                    ->where('slug', $componentSlug)
                    ->firstOrFail();

                $link = ProductGroupComponent::query()
                    ->where('product_option_group_id', $group->id)
                    ->where('menu_component_id', $component->id)
                    ->firstOrFail();

                $rows[] = ['component_link_id' => (int) $link->id];
            }
        }

        return $rows;
    }

    private function menuComponentId(Company $company, string $slug): int
    {
        return (int) MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOrFail()
            ->id;
    }
}
