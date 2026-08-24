<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\OperatingHour;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotBusinessHoursReplyBuilder;
use App\Services\Ai\CopilotProductGroundingGuard;
use App\Services\Ai\CopilotResolvedProductConfigurationService;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\OrderWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopilotDeterministicIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_request_overrides_an_incomplete_prior_n8_without_calling_the_provider(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        foreach (['quero uma marmitex n8', 'mande o cardápio'] as $body) {
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $body, 'type' => 'text', 'received_at' => now()]);
        }
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for MENU_REQUEST.');
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('MENU_REQUEST', $result['intent']);
        $this->assertSame([], $result['draft_order']['items']);
        $this->assertStringContainsString('Cardápio de hoje', $result['suggested_reply']);
        $this->assertStringNotContainsString('Qual carne', $result['suggested_reply']);
    }

    public function test_business_hours_falls_back_safely_when_no_hours_are_configured(): void
    {
        $company = $this->seededCompany();
        $company->operatingHours()->delete();
        $conversation = $this->conversation($company);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'vocês estão funcionando?', 'type' => 'text', 'received_at' => now()]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for BUSINESS_HOURS_REQUEST.');
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('BUSINESS_HOURS_REQUEST', $result['intent']);
        $this->assertSame('Vou confirmar o horário de funcionamento para você.', $result['suggested_reply']);
    }

    public function test_business_hours_uses_configured_schedule_deterministically(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 14:00:00');
        try {
            $company = $this->seededCompany();
            $company->operatingHours()->delete();
            OperatingHour::query()->create(['company_id' => $company->id, 'weekday' => 1, 'is_open' => true, 'opens_at' => '10:00', 'closes_at' => '14:00']);

            $result = app(CopilotBusinessHoursReplyBuilder::class)->build($company);

            $this->assertSame('Sim, estamos funcionando neste momento.', $result['suggested_reply']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_water_without_gas_is_recovered_as_the_canonical_company_product(): void
    {
        $company = $this->seededCompany();

        $items = app(CopilotProductGroundingGuard::class)->recoverExplicitItems($company, [[
            'direction' => 'inbound', 'type' => 'text', 'body' => '1 água sem gás',
        ]], CarbonImmutable::parse('2026-08-22'));

        $this->assertSame(['agua-mineral'], array_column($items, 'menu_item_slug'));
    }

    public function test_resolved_n8_configuration_exposes_daily_components_without_inventing_non_meat_cardinality(): void
    {
        $company = $this->seededCompany();
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $resolved = app(CopilotResolvedProductConfigurationService::class)->resolve($company, $product, CarbonImmutable::parse('2026-08-22'));
        $components = collect($resolved['daily_components'])->keyBy('slug');

        $this->assertSame(1, $resolved['meat_selection']['min']);
        $this->assertSame(2, $resolved['meat_selection']['max']);
        $this->assertTrue($resolved['allow_no_meat']);
        $this->assertSame('AVAILABLE_TODAY', $components->get('pure-de-batata')['applicability']);
        $this->assertSame('AVAILABLE_TODAY', $components->get('salada-de-macarrao')['applicability']);
        $this->assertFalse($components->get('salada-de-macarrao')['selectable']);
    }

    public function test_resolved_configuration_api_exposes_only_available_buffet_components_for_the_operational_editor(): void
    {
        $company = $this->seededCompany();
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)
            ->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-08-22&resolved=1")
            ->assertOk()
            ->json('data');

        $components = collect($response['daily_components'])->keyBy('slug');

        $this->assertSame('n8-tradicional', $response['product']['slug']);
        $this->assertSame('AVAILABLE_TODAY', $components->get('pure-de-batata')['applicability']);
        $this->assertSame('AVAILABLE_TODAY', $components->get('salada-de-macarrao')['applicability']);
        $this->assertNull($components->get('batata-frita'));
    }

    public function test_price_is_checked_against_the_backend_and_delivery_contract_survives_the_presenter(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Uma N8 com porco por 14,00 para entrega na Rua das Flores, 10. Vou pagar no cartão.', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
            {
                public function name(): string
                {
                    return 'test';
                }

                public function analyze(array $context): array
                {
                    return [
                        'intent' => 'ORDER_CREATE',
                        'draft_order' => [
                            'items' => [['product' => 'n8', 'quantity' => 1, 'selections' => ['meats' => ['porco']]]],
                            'fulfillment' => 'delivery',
                            'address' => 'Rua das Flores, 10',
                            'payment_method' => 'card',
                        ],
                        'missing_information' => [],
                        'warnings' => [],
                        'suggested_reply' => 'Pedido anotado.',
                    ];
                }
            });

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertContains('PRICE_MISMATCH', array_column($result['warnings'], 'code'));
            $this->assertSame('delivery', $result['proposal']['fulfillment']);
            $this->assertSame('Rua das Flores, 10', $result['proposal']['delivery_address']);
            $this->assertSame('card', $result['proposal']['payment_method']);
            $this->assertStringNotContainsString('anotado', $result['suggested_reply']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_manual_order_gets_a_navigation_only_conversation_when_the_customer_has_one_candidate(): void
    {
        $company = $this->seededCompany();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente manual']);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['payer_customer_id' => $customer->id]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);

        $presented = app(OperationalCrmPresenter::class)->order($order->fresh());

        $this->assertNull($order->fresh()->conversation_id);
        $this->assertSame((string) $conversation->id, $presented['resolvedConversationId']);
    }

    public function test_manual_order_does_not_resolve_an_ambiguous_customer_conversation(): void
    {
        $company = $this->seededCompany();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente ambíguo']);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['payer_customer_id' => $customer->id]);
        Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);

        $presented = app(OperationalCrmPresenter::class)->order($order->fresh());

        $this->assertNull($presented['resolvedConversationId']);
    }

    public function test_n8_buffet_and_meat_selection_round_trips_through_the_order_snapshot(): void
    {
        $company = $this->seededCompany();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de montagem livre']);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $date = CarbonImmutable::parse('2026-08-22');
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => $date,
        ]);
        $dailyMenu = app(DailyStructuredMenuService::class)->day($company, $date);
        $components = collect($dailyMenu['sections'])
            ->flatMap(fn (array $items): array => $items)
            ->keyBy(fn (array $item): string => (string) data_get($item, 'component.slug'));
        $meat = collect($dailyMenu['sections']['meat'] ?? [])->firstWhere('available', true);
        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [(int) data_get($meat, 'component.id')],
            'daily_component_ids' => [
                (int) data_get($components->get('salada-de-macarrao'), 'component.id'),
                (int) data_get($components->get('pure-de-batata'), 'component.id'),
            ],
        ];

        $created = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/items", $payload)
            ->assertOk()
            ->json('data.items.0');

        $this->assertContains('Salada de macarrão', $created['composition']);
        $this->assertContains('Purê de batata', $created['composition']);
        $this->assertContains((string) data_get($meat, 'component.display_name'), $created['composition']);
        $item = $order->refresh()->items()->firstOrFail();
        $this->assertSame($payload['daily_component_ids'], data_get($item->preferences, 'composition_snapshot.daily_component_ids'));

        $this->actingAs($user)
            ->patchJson("/api/app/orders/{$order->id}/items/{$item->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.items.0.edit.composition.daily_component_ids.0', $payload['daily_component_ids'][0]);
    }

    public function test_current_pending_n8_turn_survives_confirmation_and_delivery_details_without_mutation_claims(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            foreach ([
                'pode escolher qualquer salada, não tenho preferência; quero as duas carnes',
                'na verdade quero n8 16,00 arroz, feijão, macarrão, salada variadas, batata frita, purê, carne almôndegas e porco',
                'sim',
            ] as $body) {
                Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $body, 'type' => 'text', 'received_at' => now()]);
            }
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->emptyOrderProvider());

            $confirmed = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('ORDER_CONFIRMATION', $confirmed['intent']);
            $this->assertCount(1, $confirmed['draft_order']['items']);
            $this->assertSame('n8-tradicional', $confirmed['draft_order']['items'][0]['menu_item_slug']);
            $this->assertSame('Salada à escolha da casa', $confirmed['draft_order']['items'][0]['item_notes']);
            $this->assertNotContains('SALADA', array_column($confirmed['missing_information'], 'code'));
            $this->assertSame(1, collect($confirmed['draft_order']['items'][0]['resolved_selections'])->filter(fn (string $name): bool => $name === 'Purê de batata')->count());
            $this->assertContains('UNAVAILABLE_DAILY_COMPONENT', array_column($confirmed['warnings'], 'code'));
            $this->assertStringContainsString('N8 de R$ 16', $confirmed['suggested_reply']);
            $this->assertStringContainsString('Purê de batata', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('variadas', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('pure-de-batata', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('Deixei no rascunho', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('Pedido atualizado', $confirmed['suggested_reply']);

            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'vai ser no pix, rua quintino bocaiuva 68', 'type' => 'text', 'received_at' => now()]);

            $continued = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('ORDER_CONTINUE', $continued['intent']);
            $this->assertCount(1, $continued['draft_order']['items']);
            $this->assertSame('delivery', $continued['draft_order']['fulfillment']);
            $this->assertSame('pix', $continued['draft_order']['payment_method']);
            $this->assertStringContainsString('quintino bocaiuva', mb_strtolower((string) $continued['draft_order']['address']));
            $this->assertStringContainsString('Pix', $continued['suggested_reply']);
            $this->assertStringContainsString('Quintino Bocaiuva', $continued['suggested_reply']);
            $this->assertTrue($continued['requires_human_review']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_isolated_confirmation_does_not_revive_a_product_or_allow_a_reply_to_claim_one(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'sim', 'type' => 'text', 'received_at' => now()]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                return ['intent' => 'GENERAL_MESSAGE', 'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => null, 'payment_method' => null], 'missing_information' => [], 'warnings' => [], 'suggested_reply' => 'Deixei no rascunho 1 N8 Livre.'];
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('GENERAL_MESSAGE', $result['intent']);
        $this->assertSame([], $result['draft_order']['items']);
        $this->assertStringNotContainsString('N8', $result['suggested_reply']);
        $this->assertStringNotContainsString('rascunho', $result['suggested_reply']);
    }

    public function test_customer_facing_order_copy_uses_backend_price_and_human_labels(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero n8 porco e almondega', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->emptyOrderProvider());

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertStringContainsString('N8 de R$ 16', $result['suggested_reply']);
            $this->assertStringNotContainsString('N8 Livre', $result['suggested_reply']);
            $this->assertStringNotContainsString('n8-tradicional', $result['suggested_reply']);
            $this->assertStringNotContainsString('rascunho', $result['suggested_reply']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_customer_facing_payment_and_delivery_questions_do_not_invent_operational_facts(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->emptyOrderProvider());

        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'pode me mandar a chave pix?', 'type' => 'text', 'received_at' => now()]);
        $pix = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('PAYMENT_QUESTION', $pix['intent']);
        $this->assertSame('Vou confirmar a chave Pix para você.', $pix['suggested_reply']);
        $this->assertStringNotContainsString('confirmado', $pix['suggested_reply']);

        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'qual o valor da taxa de entrega?', 'type' => 'text', 'received_at' => now()]);
        $fee = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('DELIVERY_QUESTION', $fee['intent']);
        $this->assertSame('Ainda preciso confirmar a taxa de entrega para esse endereço.', $fee['suggested_reply']);
        $this->assertStringNotContainsString('R$', $fee['suggested_reply']);
    }

    private function emptyOrderProvider(): ConversationCopilotProviderInterface
    {
        return new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                return ['intent' => 'ORDER_CREATE', 'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => null, 'payment_method' => null], 'missing_information' => [['code' => 'SALADA', 'label' => 'Salada']], 'warnings' => [], 'suggested_reply' => 'Pedido atualizado: deixei no rascunho a N8 Livre.'];
            }
        };
    }

    private function seededCompany(): Company
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function conversation(Company $company): Conversation
    {
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de teste']);

        return Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
    }
}
