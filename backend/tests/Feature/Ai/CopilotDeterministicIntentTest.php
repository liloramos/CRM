<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliverySetting;
use App\Models\MenuComponent;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotLatestMessageIntentResolver;
use App\Services\Ai\CopilotProductGroundingGuard;
use App\Services\Ai\CopilotResolvedProductConfigurationService;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\MetaWebhookPayloadParser;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopilotDeterministicIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_n8_is_kept_as_a_candidate_before_any_meat_question(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'quero uma marmitex n8',
                'type' => 'text',
                'received_at' => now(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->fresh(),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame([], data_get($result, 'draft_order.items'));
            $this->assertSame('n8', data_get($result, 'metadata.product_candidate.family'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertContains('N8_VARIANT', array_column($result['missing_information'], 'code'));
            $this->assertStringContainsString('N8 Casa', $result['suggested_reply']);
            $this->assertStringContainsString('N8 Livre', $result['suggested_reply']);
            $this->assertStringNotContainsString('Qual carne', $result['suggested_reply']);
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_n8_candidate_is_narrowed_by_canonical_constraints_without_losing_choices(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'quero n8 arroz branco feijao macarrao vermelho mandioca abobora banana cenoura couve almondega e porco',
                'type' => 'text',
                'received_at' => now(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $recognized = collect(data_get($result, 'metadata.recognized_components', []));
            $unavailable = collect(data_get($result, 'metadata.unavailable_components', []));
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide($conversation->fresh(), $result, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

            $this->assertSame('n8-tradicional', data_get($result, 'draft_order.items.0.menu_item_slug'));
            $this->assertSame('n8', data_get($result, 'metadata.order_context.resolved_candidate.family'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertNotContains('N8_VARIANT', array_column($result['missing_information'], 'code'));
            $this->assertContains('Almôndega', $recognized->where('type', 'meat')->pluck('name'));
            $this->assertContains('Porco', $recognized->where('type', 'meat')->pluck('name'));
            $this->assertGreaterThanOrEqual(8, $recognized->merge($unavailable)->reject(fn (array $component): bool => $component['type'] === 'meat')->count());
            $this->assertContains('Abóbora cabotiá', $unavailable->pluck('name'));
            $this->assertContains('Banana frita', $unavailable->pluck('name'));
            $this->assertTrue($recognized->merge($unavailable)->every(fn (array $component): bool => $component['selection_source'] === 'customer_explicit'));
            $this->assertCount(2, data_get($result, 'draft_order.items.0.selections.meats', []));
            $this->assertContains('Porco', data_get($result, 'draft_order.items.0.selections.meats', []));
            $this->assertSame('canonical_constraints', data_get($result, 'metadata.candidate_narrowing.source'));
            $this->assertFalse($decision['requires_human_review']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_two_meats_are_kept_when_product_allows_two_and_clarified_when_it_allows_one(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $n8Livre = Product::query()->where('company_id', $company->id)->where('menu_rule_code', 'n8_tradicional')->firstOrFail();

            $freeConversation = $this->conversation($company);
            $freeConversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create(['conversation_id' => $freeConversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero n8 livre almondega e porco', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'draft_order' => ['items' => [['menu_item_id' => $n8Livre->id, 'menu_item_slug' => $n8Livre->slug, 'quantity' => 1, 'selections' => []]], 'fulfillment' => null],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Certo.',
            ]));
            $free = app(ConversationCopilotService::class)->analyze($freeConversation->fresh());
            $freeDecision = app(CopilotAutomationAuthorityPolicy::class)->decide($freeConversation, $free, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

            $this->assertContains('Almôndega', data_get($free, 'draft_order.items.0.selections.meats', []), json_encode($free, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertContains('Porco', data_get($free, 'draft_order.items.0.selections.meats', []));
            $this->assertNotContains('CARNE', array_column($free['missing_information'], 'code'));
            $this->assertNotSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $freeDecision['decision']);

            $singleConversation = $this->conversation($company);
            $singleConversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create(['conversation_id' => $singleConversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero n5 almondega e porco', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
            $single = app(ConversationCopilotService::class)->analyze($singleConversation->fresh());
            $singleDecision = app(CopilotAutomationAuthorityPolicy::class)->decide($singleConversation, $single, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

            $this->assertSame([], data_get($single, 'draft_order.items'));
            $this->assertContains('CARNE', array_column($single['missing_information'], 'code'));
            $this->assertStringContainsString('1 tipo de carne', $single['suggested_reply']);
            $this->assertStringContainsString('almôndega ou porco', mb_strtolower($single['suggested_reply']));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $singleDecision['decision']);
            $this->assertFalse($singleDecision['requires_human_review']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_common_greetings_are_deterministic_and_never_require_review(): void
    {
        $company = $this->seededCompany();

        foreach (['oi', 'olá', 'opa', 'bom dia', 'boa tarde', 'boa noite', 'e aí', 'opa bom dia', 'OPA, bom dia!'] as $greeting) {
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => $greeting,
                'type' => 'text',
                'received_at' => now(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->fresh(),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
                $greeting,
            );

            $this->assertSame('GREETING', $result['intent'], $greeting);
            $this->assertSame('deterministic_greeting', data_get($result, 'metadata.reply_source'), $greeting);
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision'], $greeting);
            $this->assertFalse($decision['requires_human_review'], $greeting);
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_product_information_is_deterministic_after_history_and_an_active_order(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quero uma N8.', 'type' => 'text', 'received_at' => now()->subMinute()]);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['conversation_id' => $conversation->id, 'payer_customer_id' => $conversation->customer_id]);
        $conversation->forceFill(['active_order_id' => $order->id])->save();
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quanto custa a N8?', 'type' => 'text', 'received_at' => now()]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for PRODUCT_CLARIFICATION.');
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
        $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
            $conversation->fresh()->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
            $result,
            CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW,
            true,
            'Quanto custa a N8?',
        );

        $this->assertSame('PRODUCT_CLARIFICATION', $result['intent']);
        $this->assertSame([], $result['draft_order']['items']);
        $this->assertStringContainsString('R$ 16,00', $result['suggested_reply']);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, $decision['decision']);
        $this->assertSame('send_grounded_reply', $decision['action']);
    }

    public function test_price_availability_and_composition_questions_are_not_order_requests(): void
    {
        $resolver = app(CopilotLatestMessageIntentResolver::class);

        foreach ([
            'Quanto custa a N8?',
            'Qual o valor da N8?',
            'Qual o preco da N9?',
            'Quanto e a separadinha?',
            'Tem N8?',
            'Tem suco de laranja?',
            'Tem Coca Zero?',
            'O que vem na N8?',
        ] as $message) {
            $this->assertSame('PRODUCT_CLARIFICATION', $resolver->resolve(['messages' => [['direction' => 'inbound', 'type' => 'text', 'body' => $message]]]), $message);
        }

        foreach (['Quero uma N8', 'Me ve uma N8', 'Pode fazer uma N8', 'Vou querer uma N8', 'Adiciona uma Coca'] as $message) {
            $this->assertSame('ORDER_CREATE', $resolver->resolve(['messages' => [['direction' => 'inbound', 'type' => 'text', 'body' => $message]]]), $message);
        }
    }

    public function test_bare_order_start_request_gets_a_safe_deterministic_reply_without_human_review(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Queria fazer um pedido.', 'type' => 'text', 'received_at' => now()]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for a bare order start request.');
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
            $result,
            CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
            true,
            'Queria fazer um pedido.',
        );

        $this->assertSame('ORDER_CREATE', $result['intent']);
        $this->assertSame('order_start', data_get($result, 'metadata.reply_source'));
        $this->assertSame([], $result['draft_order']['items']);
        $this->assertSame('Claro! O que você gostaria de pedir?', $result['suggested_reply']);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
        $this->assertSame('send_grounded_reply', $decision['action']);
        $this->assertFalse($decision['requires_human_review']);
    }

    public function test_product_availability_uses_the_catalog_component_and_never_calls_the_provider(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Tem suco de laranja?', 'type' => 'text', 'received_at' => now()]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for PRODUCT_CLARIFICATION.');
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('PRODUCT_CLARIFICATION', $result['intent']);
        $this->assertSame('product_catalog', $result['metadata']['reply_source']);
        $this->assertStringContainsString('Laranja', $result['suggested_reply']);
        $this->assertSame([], $result['draft_order']['items']);
    }

    public function test_n8_with_two_unambiguous_daily_meats_is_a_safe_candidate(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quero uma N8 de 16 com frango e porco.', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'confidence' => 0.9,
                'draft_order' => ['items' => [['menu_item_slug' => 'n8', 'quantity' => 1, 'selections' => []]], 'fulfillment' => 'pickup'],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Resumo do pedido.',
            ]));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
                'Quero uma N8 de 16 com frango e porco.',
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame([], $result['missing_information'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
            $this->assertSame([], $result['warnings']);
            $meats = $result['draft_order']['items'][0]['selections']['meats'];
            $this->assertCount(2, $meats);
            $this->assertContains('Porco', $meats);
            $this->assertTrue(collect($meats)->contains(fn (string $meat): bool => str_contains(mb_strtolower($meat), 'frango')));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertSame('send_grounded_reply', $decision['action']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ambiguous_daily_meat_keeps_the_n8_order_as_a_normal_clarification(): void
    {
        CarbonImmutable::setTestNow('2026-08-26 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quero uma N8 de 16 com frango e porco.', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'confidence' => 0.9,
                'draft_order' => ['items' => [['menu_item_slug' => 'n8', 'quantity' => 1, 'selections' => ['meats' => ['frango', 'porco']]]], 'fulfillment' => 'pickup'],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Resumo do pedido.',
            ]));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW,
                true,
                'Quero uma N8 de 16 com frango e porco.',
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertContains('CARNE', array_column($result['missing_information'], 'code'));
            $this->assertContains('AMBIGUOUS_MEAT', array_column($result['warnings'], 'code'));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_n8_meat_follow_up_keeps_the_current_order_turn_grounded_for_review(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            foreach (['Quero uma N8 de 16.', 'Com frango e porco.'] as $body) {
                Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $body, 'type' => 'text', 'received_at' => now()]);
            }
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'confidence' => 0.9,
                'draft_order' => ['items' => [['menu_item_slug' => 'n8', 'quantity' => 1, 'selections' => []]], 'fulfillment' => 'pickup'],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Resumo do pedido.',
            ]));

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('ORDER_CONTINUE', $result['intent']);
            $this->assertSame([], $result['missing_information']);
            $this->assertSame([], $result['warnings']);
            $this->assertCount(2, $result['draft_order']['items'][0]['selections']['meats'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
            $this->assertTrue(collect($result['draft_order']['items'][0]['selections']['meats'])->contains(fn (string $meat): bool => str_contains(mb_strtolower($meat), 'frango')));
            $this->assertContains('Porco', $result['draft_order']['items'][0]['selections']['meats']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_removal_follow_up_keeps_the_current_order_turn_grounded(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            foreach (['Quero uma N5 de porco.', 'Sem salada.'] as $body) {
                Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $body, 'type' => 'text', 'received_at' => now()]);
            }
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'confidence' => 0.9,
                'draft_order' => ['items' => [[
                    'menu_item_slug' => 'n5',
                    'quantity' => 1,
                    'selections' => ['meat' => 'porco'],
                    'removed_components' => ['salada'],
                ]], 'fulfillment' => 'pickup'],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Resumo do pedido.',
            ]));

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('ORDER_CONTINUE', $result['intent']);
            $this->assertContains('Sem Salada', $result['draft_order']['items'][0]['removed_components']);
            $this->assertNotContains('UNGROUNDED_REMOVAL', array_column($result['warnings'], 'code'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_n8_free_assembly_salad_opt_out_is_explicit_and_does_not_leak_into_the_next_order(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
            {
                private int $calls = 0;

                public function name(): string
                {
                    return 'test';
                }

                public function analyze(array $context): array
                {
                    $this->calls++;

                    return [
                        'intent' => 'ORDER_CREATE',
                        'confidence' => 0.9,
                        'draft_order' => ['items' => [[
                            'menu_item_slug' => 'n8-tradicional',
                            'quantity' => 1,
                            'selections' => [
                                'meat' => 'porco',
                                ...($this->calls === 3 ? ['salada' => 'none'] : []),
                            ],
                            'removed_components' => $this->calls === 2 ? ['salada'] : [],
                        ]], 'fulfillment' => 'pickup'],
                        'missing_information' => [],
                        'warnings' => $this->calls === 2 ? [[
                            'code' => 'REMOVAL_NOT_SUPPORTED',
                            'message' => 'A montagem livre nao suporta remocao de salada.',
                        ]] : [],
                        'suggested_reply' => 'Resumo do pedido.',
                    ];
                }
            });

            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quero uma N8 de 16 com porco.', 'type' => 'text', 'received_at' => now()]);
            $first = app(ConversationCopilotService::class)->analyze($conversation);
            $this->assertSame('ORDER_CREATE', $first['intent']);
            $this->assertSame([], $first['warnings']);
            $this->assertNotSame('none', data_get($first, 'draft_order.items.0.selections.salada'));

            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Sem salada.', 'type' => 'text', 'received_at' => now()]);
            $followUp = app(ConversationCopilotService::class)->analyze($conversation);
            $followUpItem = $followUp['draft_order']['items'][0];
            $this->assertSame('ORDER_CONTINUE', $followUp['intent']);
            $this->assertSame('none', data_get($followUpItem, 'selections.salada'));
            $this->assertSame([], $followUpItem['removed_components']);
            $this->assertContains('Sem salada', array_column($followUpItem['validated_order_options'], 'name'));
            $this->assertSame([], $followUp['warnings']);
            $this->assertSame([], $followUp['missing_information'], json_encode($followUp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');

            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quero uma N8 de 16 com porco.', 'type' => 'text', 'received_at' => now()]);
            $fresh = app(ConversationCopilotService::class)->analyze($conversation);
            $freshItem = $fresh['draft_order']['items'][0];
            $this->assertSame('ORDER_CREATE', $fresh['intent']);
            $this->assertNotSame('none', data_get($freshItem, 'selections.salada'));
            $this->assertSame([], $freshItem['removed_components']);
            $this->assertNotContains('Sem salada', array_column($freshItem['validated_order_options'], 'name'));
            $this->assertSame([], $fresh['warnings']);
            $this->assertSame([], $fresh['missing_information']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_menu_request_overrides_an_incomplete_prior_n8_with_progressive_disclosure(): void
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
        $this->assertStringNotContainsString('cardápio completo de hoje', $result['suggested_reply']);
        $this->assertStringContainsString('*MARMITAS*', $result['suggested_reply']);
        $this->assertStringContainsString('*N5 Casa – R$ 8,00*', $result['suggested_reply']);
        $this->assertStringContainsString('Marmitex de aproximadamente 500 ml', $result['suggested_reply']);
        $this->assertStringContainsString('N5 Casa', $result['suggested_reply']);
        $this->assertStringContainsString('N8 Livre', $result['suggested_reply']);
        $this->assertStringContainsString('*Buffet de hoje*', $result['suggested_reply']);
        $this->assertStringContainsString('*Carnes de hoje*', $result['suggested_reply']);
        $this->assertStringNotContainsString('*BEBIDAS*', $result['suggested_reply']);
        $this->assertStringNotContainsString('Coca-Cola 2L', $result['suggested_reply']);
        $this->assertStringNotContainsString('Qual carne', $result['suggested_reply']);
        $this->assertLessThanOrEqual(3, count($result['reply_messages']));
        $this->assertSame(0, $company->orders()->count());
    }

    public function test_generic_menu_is_progressive_and_complete_menu_is_explicit(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'qual o cardápio de hoje?', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
            $dailyMenu = app(DailyStructuredMenuService::class)->day($company, CarbonImmutable::now());
            $buffetItem = collect($dailyMenu['sections'])
                ->except('meat')
                ->flatten(1)
                ->first(fn (array $item): bool => (bool) ($item['available'] ?? false));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $customerReply = implode("\n\n", $result['reply_messages']);

            $this->assertSame('MENU_REQUEST', $result['intent']);
            $this->assertStringNotContainsString('cardápio completo de hoje', $customerReply);
            $this->assertStringContainsString('*MARMITAS*', $customerReply);
            $this->assertStringNotContainsString('*BEBIDAS*', $customerReply);
            $this->assertStringContainsString('N5 Casa', $customerReply);
            $this->assertStringContainsString('N8 Livre', $customerReply);
            $this->assertStringNotContainsString('Coca-Cola 2L', $customerReply);
            $this->assertMatchesRegularExpression('/1 (?:tipo de )?carne/u', $customerReply);
            $this->assertMatchesRegularExpression('/até 2 (?:tipos? de )?carnes?/u', $customerReply);
            $this->assertStringContainsString('*Buffet de hoje*', $customerReply);
            $this->assertStringContainsString((string) data_get($buffetItem, 'component.display_name'), $customerReply);
            $this->assertStringContainsString('*Carnes de hoje*', $customerReply);
            $this->assertStringContainsString('monte uma marmita', mb_strtolower($customerReply));
            $this->assertStringContainsString('bebidas', mb_strtolower($customerReply));
            $this->assertStringNotContainsString('ver outra categoria', mb_strtolower($customerReply));
            $this->assertStringContainsString("\n", $customerReply);
            $this->assertStringContainsString('•', implode("\n", $result['reply_messages']));
            $this->assertLessThanOrEqual(2, count($result['reply_messages']));
            $this->assertTrue(collect($result['reply_messages'])->contains(fn (string $message): bool => substr_count($message, "\n") >= 2));
            $this->assertSame([], $result['draft_order']['items']);
            $this->assertSame(0, $company->orders()->count());

            $completeConversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $completeConversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'manda o cardápio completo', 'type' => 'text', 'received_at' => now()]);
            $complete = app(ConversationCopilotService::class)->analyze($completeConversation);
            $completeReply = implode("\n\n", $complete['reply_messages']);
            $this->assertStringContainsString('cardápio completo de hoje', $completeReply);
            $this->assertStringContainsString('*BEBIDAS*', $completeReply);
            $this->assertStringContainsString('Coca-Cola 2L', $completeReply);
            $this->assertLessThanOrEqual(3, count($complete['reply_messages']));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ambiguous_n8_with_menu_question_stays_focused_and_preserves_the_candidate(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'marmitex n8 qual o cardapio de hoje',
                'type' => 'text',
                'received_at' => now(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $reply = implode("\n\n", $result['reply_messages']);
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide($conversation->fresh(), $result, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame([], data_get($result, 'draft_order.items'));
            $this->assertSame('n8', data_get($result, 'metadata.product_candidate.family'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('N8 Casa', $reply);
            $this->assertStringContainsString('N8 Livre', $reply);
            $this->assertStringContainsString('Buffet de hoje', $reply);
            $this->assertStringContainsString('Carnes de hoje', $reply);
            $this->assertStringNotContainsString('*BEBIDAS*', $reply);
            $this->assertStringNotContainsString('Coca-Cola 2L', $reply);
            $this->assertLessThanOrEqual(3, count($result['reply_messages']));
            $this->assertFalse($decision['requires_human_review']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_order_and_buffet_question_in_the_same_message_preserve_both_intents(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $n8 = Product::query()->where('company_id', $company->id)->where('menu_rule_code', 'n8_tradicional')->firstOrFail();
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'quero uma N8 Livre, qual o buffet de hoje?',
                'type' => 'text',
                'received_at' => now(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'confidence' => 0.99,
                'draft_order' => ['items' => [[
                    'menu_item_id' => $n8->id,
                    'menu_item_slug' => $n8->slug,
                    'quantity' => 1,
                    'selections' => [],
                ]], 'fulfillment' => null],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Perfeito, N8 Livre. Qual carne você deseja?',
            ]));
            $dailyMenu = app(DailyStructuredMenuService::class)->day($company, CarbonImmutable::now());
            $buffetItem = collect($dailyMenu['sections'])
                ->except('meat')
                ->flatten(1)
                ->first(fn (array $item): bool => (bool) ($item['available'] ?? false));

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->fresh(),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame('n8-tradicional', data_get($result, 'draft_order.items.0.menu_item_slug'));
            $this->assertContains('CARNE', array_column($result['missing_information'], 'code'));
            $this->assertStringContainsString('*Buffet de hoje*', $result['suggested_reply']);
            $this->assertStringContainsString((string) data_get($buffetItem, 'component.display_name'), $result['suggested_reply']);
            $this->assertStringContainsString('carne', mb_strtolower($result['suggested_reply']));
            $this->assertLessThanOrEqual(2, count($result['reply_messages']));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_restaurant_location_uses_the_configured_delivery_origin_without_calling_the_provider(): void
    {
        $company = $this->seededCompany();
        DeliverySetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['provider_options' => ['origin' => ['address' => 'Rua Configurada, 123', 'latitude' => -16.0, 'longitude' => -49.0]]],
        );
        $conversation = $this->conversation($company);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'onde fica o restaurante?', 'type' => 'text', 'received_at' => now()]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for LOCATION_REQUEST.');
            }
        });

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('LOCATION_REQUEST', $result['intent']);
        $this->assertStringContainsString('Rua Configurada, 123', $result['suggested_reply']);
        $this->assertSame('customer_facing_policy', data_get($result, 'metadata.reply_source'));
        $this->assertSame([], $result['draft_order']['items']);
        $this->assertSame(0, $company->orders()->count());
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
        $this->assertSame('O horário de funcionamento ainda não está configurado aqui.', $result['suggested_reply']);
        $this->assertSame('UNKNOWN', data_get($result, 'metadata.operational_status'));
    }

    public function test_business_hours_uses_configured_schedule_deterministically(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 11:00:00', 'America/Sao_Paulo'));
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Vocês estão abertos?', 'type' => 'text', 'received_at' => now()]);
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
            $this->assertStringContainsString('Sim, estamos abertos agora', $result['suggested_reply']);
            $this->assertStringContainsString('de segunda-feira a sábado, das 10:30 às 14:00', $result['suggested_reply']);
            $this->assertSame('OPEN', data_get($result, 'metadata.operational_status'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sunday_menu_request_reports_closed_from_canonical_hours_without_review_or_mutation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-30 12:00:00', 'America/Sao_Paulo'));
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Qual o cardápio de hoje?', 'type' => 'text', 'received_at' => now()]);

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
                'Qual o cardápio de hoje?',
            );

            $this->assertSame('MENU_REQUEST', $result['intent']);
            $this->assertSame('CLOSED', data_get($result, 'metadata.operational_status'));
            $this->assertStringContainsString('Hoje estamos fechados', $result['suggested_reply']);
            $this->assertStringContainsString('de segunda-feira a sábado, das 10:30 às 14:00', $result['suggested_reply']);
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
            $this->assertSame(0, $company->orders()->count());
            $this->assertSame(0, $company->payments()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_open_restaurant_with_available_menu_keeps_the_current_menu_flow(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 11:00:00', 'America/Sao_Paulo'));
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Qual o cardápio de hoje?', 'type' => 'text', 'received_at' => now()]);

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('OPEN', data_get($result, 'metadata.operational_status'));
            $this->assertTrue((bool) data_get($result, 'metadata.menu_available'));
            $this->assertStringContainsString('*MARMITAS*', implode("\n\n", $result['reply_messages']));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_order_attempt_while_closed_gets_hours_without_calling_the_provider_or_creating_an_order(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-30 12:00:00', 'America/Sao_Paulo'));
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Quero uma N8.', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
            {
                public function name(): string
                {
                    return 'test';
                }

                public function analyze(array $context): array
                {
                    throw new \LogicException('Provider must not be called for an order while the restaurant is closed.');
                }
            });

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
                'Quero uma N8.',
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame('CLOSED', data_get($result, 'metadata.operational_status'));
            $this->assertSame([], $result['draft_order']['items']);
            $this->assertStringContainsString('Hoje estamos fechados', $result['suggested_reply']);
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertContains('restaurant_closed', $decision['reason_codes']);
            $this->assertSame(0, $company->orders()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_open_restaurant_without_available_menu_does_not_claim_it_is_closed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 11:00:00', 'America/Sao_Paulo'));
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            Product::query()->where('company_id', $company->id)->update(['is_active' => false]);
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Tem almoço hoje?', 'type' => 'text', 'received_at' => now()]);

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('MENU_REQUEST', $result['intent']);
            $this->assertSame('OPEN', data_get($result, 'metadata.operational_status'));
            $this->assertFalse((bool) data_get($result, 'metadata.menu_available'));
            $this->assertSame('Ainda não tenho o cardápio de hoje disponível aqui 😊', $result['suggested_reply']);
            $this->assertStringNotContainsString('fechado', mb_strtolower($result['suggested_reply']));
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
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Uma N8 Livre com porco por 14,00 para entrega na Rua das Flores, 10. Vou pagar no cartão.', 'type' => 'text', 'received_at' => now()]);
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
                            'items' => [['product' => 'n8-tradicional', 'quantity' => 1, 'selections' => ['meats' => ['porco']]]],
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
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->emptyOrderProvider());
            foreach ([
                'pode escolher qualquer salada, não tenho preferência; quero as duas carnes',
                'na verdade quero n8 16,00 arroz, feijão, macarrão, salada variadas, batata frita, purê, carne almôndegas e porco',
            ] as $body) {
                $pendingMessage = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $body, 'type' => 'text', 'received_at' => now()]);
                $pending = app(ConversationCopilotService::class)->analyze($conversation);
                if (str_contains($body, 'n8 16')) {
                    $this->assertNotEmpty(data_get($pending, 'draft_order.items.0.daily_component_candidates'), json_encode([
                        'draft' => data_get($pending, 'draft_order'),
                        'candidate' => data_get($pending, 'metadata.candidate_item'),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
                }
                AutomationEvent::query()->create([
                    'company_id' => $company->id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $pendingMessage->id,
                    'provider' => 'copilot',
                    'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
                    'status' => AutomationEvent::STATUS_DISPATCHED,
                    'payload' => [
                        'order_context' => data_get($pending, 'metadata.order_context'),
                        'assistant_goal' => data_get($pending, 'metadata.assistant_goal'),
                    ],
                    'processed_at' => now(),
                ]);
                Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'ai', 'direction' => 'outbound', 'content' => $pending['suggested_reply'], 'type' => 'text', 'sent_at' => now()]);
            }
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'sim', 'type' => 'text', 'received_at' => now()]);

            $confirmed = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('GENERAL_MESSAGE', $confirmed['intent']);
            $this->assertCount(1, $confirmed['draft_order']['items'], json_encode([
                'confirmed' => $confirmed,
                'context' => app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
            $this->assertSame('n8-tradicional', $confirmed['draft_order']['items'][0]['menu_item_slug']);
            $this->assertSame('', $confirmed['draft_order']['items'][0]['item_notes']);
            $this->assertNotContains('SALADA', array_column($confirmed['missing_information'], 'code'));
            $this->assertSame(1, collect($confirmed['draft_order']['items'][0]['resolved_selections'])->filter(fn (string $name): bool => $name === 'Purê de batata')->count());
            $candidateNames = collect((array) data_get($confirmed, 'draft_order.items.0.daily_component_candidates'))
                ->flatMap(fn (array $candidate): array => (array) ($candidate['names'] ?? []))
                ->all();
            $this->assertContains('Macarrão vermelho', $candidateNames, json_encode([
                'item' => data_get($confirmed, 'draft_order.items.0'),
                'candidate_item' => data_get($confirmed, 'metadata.candidate_item'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
            $this->assertContains('Macarrão alho e óleo', $candidateNames);
            $this->assertContains('ACOMPANHAMENTO', array_column($confirmed['missing_information'], 'code'));
            $this->assertStringContainsString('Macarrão vermelho', $confirmed['suggested_reply']);
            $this->assertStringContainsString('Macarrão alho e óleo', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('variadas', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('pure-de-batata', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('Deixei no rascunho', $confirmed['suggested_reply']);
            $this->assertStringNotContainsString('Pedido atualizado', $confirmed['suggested_reply']);

            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'vai ser no pix, rua quintino bocaiuva 68', 'type' => 'text', 'received_at' => now()]);
            $continuedDraft = (array) data_get(
                app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh()),
                'pending_order_state.draft_order',
            );
            $continuedDraft['fulfillment'] = 'delivery';
            $continuedDraft['address'] = 'rua quintino bocaiuva 68';
            $continuedDraft['payment_method'] = 'pix';
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CONTINUE',
                'confidence' => 0.99,
                'draft_order' => $continuedDraft,
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Certo.',
                'interpretation' => [
                    'intents' => ['order_request'],
                    'subject' => 'order',
                    'candidate_value' => 'pix',
                    'candidate_values' => ['delivery', 'pix', 'rua quintino bocaiuva 68'],
                    'target_products' => [],
                    'reference' => 'pending_order_state',
                    'selected_option_index' => null,
                    'mutates_order' => true,
                    'order_delta' => [
                        'product_reference' => '',
                        'components' => [],
                        'meats' => [],
                        'beverages' => [],
                        'exclusions' => [],
                        'corrections' => [],
                        'replacements' => [],
                        'quantity' => null,
                        'fulfillment' => 'delivery',
                        'address' => 'rua quintino bocaiuva 68',
                        'payment_method' => 'pix',
                    ],
                    'facts_needed' => ['payment_methods'],
                    'reply_goal' => 'continue_order',
                    'state_operations' => [],
                ],
            ]));

            $continued = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame('UNKNOWN', $continued['intent']);
            $this->assertCount(1, $continued['draft_order']['items']);
            $this->assertNull($continued['draft_order']['fulfillment']);
            $this->assertSame('', $continued['draft_order']['payment_method']);
            $this->assertSame('', $continued['draft_order']['address']);
            $this->assertStringNotContainsString('Pix', $continued['suggested_reply']);
            $this->assertStringContainsString('Macarrão vermelho', $continued['suggested_reply']);
            $this->assertTrue($continued['requires_human_review']);
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->fresh(),
                $continued,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
                'vai ser no pix, rua quintino bocaiuva 68',
            );
            $this->assertNotSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
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

    public function test_customer_facing_n8_constraints_narrow_to_the_only_compatible_variant(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $conversation = $this->conversation($company);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero n8 porco e almondega', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->emptyOrderProvider());

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertStringContainsString('N8 Livre', $result['suggested_reply']);
            $this->assertStringNotContainsString('N8 Casa', $result['suggested_reply']);
            $this->assertSame('n8', data_get($result, 'metadata.candidate_item.family'));
            $this->assertSame('resolved', data_get($result, 'metadata.candidate_item.status'));
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
        $this->assertSame('Ainda não tenho a chave Pix disponível aqui.', $pix['suggested_reply']);
        $this->assertFalse((bool) data_get($pix, 'metadata.pix_configured'));
        $this->assertStringNotContainsString('confirmado', $pix['suggested_reply']);

        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'qual o valor da taxa de entrega?', 'type' => 'text', 'received_at' => now()]);
        $fee = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('DELIVERY_QUESTION', $fee['intent']);
        $this->assertSame('Ainda preciso confirmar a taxa de entrega para esse endereço.', $fee['suggested_reply']);
        $this->assertStringNotContainsString('R$', $fee['suggested_reply']);
    }

    public function test_pix_information_uses_only_the_company_configured_customer_facing_key(): void
    {
        $company = $this->seededCompany();
        $company->setting()->updateOrCreate([], [
            'timezone' => 'America/Sao_Paulo',
            'settings' => [
                'payments' => [
                    'methods' => [
                        'pix' => true,
                        'cash' => false,
                        'debit_card' => true,
                        'credit_card' => true,
                        'customer_credit' => false,
                        'other' => false,
                    ],
                    'pix' => [
                        'public_key' => 'pix-chave-teste',
                        'holder_name' => 'Restaurante Sol',
                    ],
                ],
            ],
        ]);
        $conversation = $this->conversation($company->fresh('setting'));
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->emptyOrderProvider());

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => 'inbound',
            'content' => 'qual o Pix?',
            'type' => 'text',
            'received_at' => now(),
        ]);

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('PAYMENT_QUESTION', $result['intent']);
        $this->assertStringStartsWith('A chave Pix é pix-chave-teste. Favorecido: Restaurante Sol.', $result['suggested_reply']);
        $this->assertCount(1, $result['reply_messages']);
        $this->assertStringContainsString('comprovante', mb_strtolower($result['reply_messages'][0]));
        $this->assertTrue((bool) data_get($result, 'metadata.pix_configured'));
        $this->assertStringNotContainsString('confirmado', $result['suggested_reply']);
        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());
        $this->assertSame('pix-chave-teste', data_get($context, 'payment.pix_key'));
        $this->assertContains('pix', data_get($context, 'payment.available_methods'));
        $this->assertNotContains('cash', data_get($context, 'payment.available_methods'));
    }

    public function test_informal_component_list_is_a_grounded_product_clarification_without_human_review(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'arroz feijão batata doce repolho alho e oleo peixe empanado',
                'type' => 'text',
                'received_at' => now(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->fresh(),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame('order_clarification', data_get($result, 'metadata.reply_source'));
            $this->assertContains('MENU_ITEM', array_column($result['missing_information'], 'code'));
            $this->assertContains('ITEM_UNAVAILABLE', array_column($result['warnings'], 'code'));
            $this->assertStringContainsString('Filé de peixe empanado', $result['suggested_reply']);
            $this->assertStringContainsString('carnes disponíveis', mb_strtolower($result['suggested_reply']));
            $this->assertStringContainsString('marmitas', mb_strtolower($result['suggested_reply']));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_plain_unpunctuated_ingredient_list_waits_for_product_without_persisting(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'arroz feijao macarrao porco beterraba', 'type' => 'text', 'received_at' => now()]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide($conversation, $result, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

            $recognized = collect(data_get($result, 'metadata.recognized_components', []))->pluck('name');
            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertContains('Porco', $recognized);
            $this->assertContains('Beterraba', $recognized);
            $this->assertStringContainsString('marmitas', mb_strtolower($result['suggested_reply']));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertSame('normal_missing_information', $decision['reason_codes'][0]);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_generic_marmita_and_light_typo_are_progressive_clarifications(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);

            foreach ([
                'quero uma marmita' => ['marmitas', null],
                'quero frnago' => ['você quis dizer', 'POSSIBLE_TYPO'],
                'quero produto lunar' => ['não encontrei essa opção', null],
            ] as $body => [$expectedReply, $warning]) {
                $conversation = $this->conversation($company);
                $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
                Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $body, 'type' => 'text', 'received_at' => now()]);
                $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

                $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
                $decision = app(CopilotAutomationAuthorityPolicy::class)->decide($conversation->fresh(), $result, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

                $this->assertSame('ORDER_CREATE', $result['intent']);
                $this->assertStringContainsString($expectedReply, mb_strtolower($result['suggested_reply']));
                if ($warning !== null) {
                    $this->assertContains($warning, array_column($result['warnings'], 'code'));
                }
                $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
                $this->assertFalse($decision['requires_human_review']);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_components_before_product_survive_n8_livre_selection_and_are_revalidated(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $source = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'quero n8 arroz feijao porco beterraba',
                'type' => 'text',
                'received_at' => now()->subMinute(),
            ]);
            $components = MenuComponent::query()
                ->where('company_id', $company->id)
                ->whereIn('slug', ['arroz', 'feijao', 'porco', 'beterraba'])
                ->get()
                ->map(fn (MenuComponent $component): array => [
                    'id' => (int) $component->id,
                    'name' => (string) $component->name,
                    'type' => $component->component_type->value,
                ])
                ->values()
                ->all();
            $initialCandidate = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $this->assertSame('n8', data_get($initialCandidate, 'metadata.candidate_item.family'));
            AutomationEvent::query()->create([
                'company_id' => $company->id,
                'conversation_id' => $conversation->id,
                'message_id' => $source->id,
                'provider' => 'copilot',
                'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
                'status' => AutomationEvent::STATUS_DISPATCHED,
                'payload' => [
                    'action' => 'send_order_clarification',
                    'clarification_context' => [
                        'type' => 'product_selection',
                        'source_message_id' => $source->id,
                        'active_order_id' => null,
                        'recognized_components' => $components,
                    ],
                ],
                'response_payload' => ['execution_result' => 'completed'],
                'processed_at' => now(),
            ]);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'ai', 'direction' => 'outbound', 'content' => 'Qual marmitex você gostaria?', 'type' => 'text', 'received_at' => now()->subSeconds(30)]);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quais os valores das marmitas?', 'type' => 'text', 'received_at' => now()->subSeconds(20)]);
            $prices = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $this->assertSame('PRODUCT_CLARIFICATION', $prices['intent']);
            $this->assertStringContainsString('R$', $prices['suggested_reply']);
            $this->assertSame('product_selection', data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_clarification.type'));
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'ai', 'direction' => 'outbound', 'content' => $prices['suggested_reply'], 'type' => 'text', 'received_at' => now()->subSeconds(10)]);
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'n8 livre', 'type' => 'text', 'received_at' => now()]);
            $continuedContext = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());
            $this->assertTrue(collect(data_get($continuedContext, 'messages'))->contains(
                fn (array $message): bool => $message['direction'] === 'outbound'
                    && str_contains($message['body'], 'R$'),
            ));
            $this->assertContains('Porco', collect(data_get($continuedContext, 'pending_order_state.selected_components'))->pluck('name'));
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
                'intent' => 'ORDER_CREATE',
                'draft_order' => ['items' => [['menu_item_slug' => 'n8-tradicional', 'quantity' => 1, 'selections' => []]], 'fulfillment' => null],
                'missing_information' => [],
                'warnings' => [],
                'suggested_reply' => 'Resumo.',
            ]));

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame('n8-tradicional', data_get($result, 'draft_order.items.0.menu_item_slug'));
            $this->assertContains('Porco', (array) data_get($result, 'draft_order.items.0.selections.meats', []), json_encode(data_get($result, 'draft_order.items.0'), JSON_PRETTY_PRINT));
            $this->assertContains('Beterraba', (array) data_get($result, 'draft_order.items.0.resolved_selections', []));
            $this->assertStringContainsString('está tudo certo', mb_strtolower($result['suggested_reply']));
            $this->assertCount(1, $result['reply_messages']);
            $this->assertSame('product_selection', data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_clarification.type'));
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide($conversation, $result, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);
            $this->assertNotSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $decision['decision']);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_incompatible_n5_casa_does_not_replace_explicit_components_with_house_defaults(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            $conversation = $this->conversation($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $source = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'arroz feijao batata doce repolho alho e oleo almondega',
                'type' => 'text',
                'received_at' => now()->subMinute(),
            ]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
            $initial = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $components = (array) data_get($initial, 'metadata.recognized_components', []);
            AutomationEvent::query()->create([
                'company_id' => $company->id,
                'conversation_id' => $conversation->id,
                'message_id' => $source->id,
                'provider' => 'copilot',
                'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
                'status' => AutomationEvent::STATUS_DISPATCHED,
                'payload' => [
                    'action' => 'send_order_clarification',
                    'clarification_context' => [
                        'type' => 'product_selection',
                        'source_message_id' => $source->id,
                        'active_order_id' => null,
                        'recognized_components' => $components,
                    ],
                ],
                'response_payload' => ['execution_result' => 'completed'],
                'processed_at' => now(),
            ]);
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'direction' => 'inbound',
                'content' => 'N5 da Casa',
                'type' => 'text',
                'received_at' => now(),
            ]);

            $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $decision = app(CopilotAutomationAuthorityPolicy::class)->decide(
                $conversation->fresh(),
                $result,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
                true,
            );

            $this->assertSame('ORDER_CREATE', $result['intent']);
            $this->assertSame([], $result['draft_order']['items']);
            $this->assertContains('PRODUCT_INCOMPATIBLE_WITH_EXPLICIT_SELECTIONS', array_column($result['warnings'], 'code'));
            $this->assertStringContainsString('composição definida', $result['suggested_reply']);
            $this->assertStringContainsString('Batata doce', $result['suggested_reply']);
            $this->assertStringContainsString('Repolho alho e óleo', $result['suggested_reply']);
            $this->assertStringContainsString('marmitex Livre', $result['suggested_reply']);
            $this->assertCount(1, $result['reply_messages']);
            $this->assertSame(1, substr_count($result['suggested_reply'], 'Batata doce'));
            $this->assertTrue(collect(data_get($result, 'metadata.recognized_components'))->every(
                fn (array $component): bool => ($component['selection_source'] ?? null) === 'customer_explicit',
            ));
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $decision['decision']);
            $this->assertFalse($decision['requires_human_review']);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_read_only_questions_do_not_consume_pending_product_selection(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');
        try {
            $company = $this->seededCompany();
            $this->configureOfficialHours($company);
            DeliverySetting::query()->updateOrCreate(['company_id' => $company->id], ['provider_options' => ['origin' => ['address' => 'Rua do Sol, 100']]]);
            $conversation = $this->conversation($company);
            $source = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'arroz feijao porco beterraba', 'type' => 'text', 'received_at' => now()->subMinutes(2)]);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
            $initial = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $components = data_get($initial, 'metadata.recognized_components', []);
            AutomationEvent::query()->create([
                'company_id' => $company->id,
                'conversation_id' => $conversation->id,
                'message_id' => $source->id,
                'provider' => 'copilot',
                'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
                'status' => AutomationEvent::STATUS_DISPATCHED,
                'payload' => ['action' => 'send_order_clarification', 'clarification_context' => ['type' => 'product_selection', 'source_message_id' => $source->id, 'active_order_id' => null, 'recognized_components' => $components]],
                'response_payload' => ['execution_result' => 'completed'],
                'processed_at' => now(),
            ]);
            foreach ([
                'onde fica o restaurante?' => 'Rua do Sol, 100',
                'que horas fecha?' => '14:00',
                'quais os valores das marmitas?' => 'R$',
                'e quantas carnes?' => 'carne',
            ] as $question => $expected) {
                Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $question, 'type' => 'text', 'received_at' => now()]);
                $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());
                $this->assertStringContainsString($expected, $result['suggested_reply'], json_encode($result, JSON_PRETTY_PRINT));
                $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());
                $this->assertSame('product_selection', data_get($context, 'pending_clarification.type'));
                $this->assertGreaterThanOrEqual(2, count(data_get($context, 'pending_clarification.recognized_components', [])));
            }

            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_whatsapp_location_is_projected_into_context_without_requesting_the_full_address_again(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        $incoming = app(MetaWebhookPayloadParser::class)->parse([
            'messages' => [[
                'from' => '5562999999999',
                'type' => 'location',
                'location' => [
                    'latitude' => -16.3267,
                    'longitude' => -48.9528,
                    'name' => 'Portaria',
                    'address' => 'Rua de Teste, 123',
                ],
            ]],
        ], 'fake')[0];
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => 'inbound',
            'content' => 'Localização compartilhada',
            'type' => 'location',
            'metadata' => $incoming->safeMetadata,
            'received_at' => now(),
        ]);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());
        $result = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame(-16.3267, data_get($context, 'latest_message.location.latitude'));
        $this->assertSame(-48.9528, data_get($context, 'latest_message.location.longitude'));
        $this->assertSame('Portaria', data_get($context, 'latest_message.location.name'));
        $this->assertSame('Rua de Teste, 123', data_get($context, 'latest_message.location.address'));
        $this->assertSame('ORDER_CONTINUE', $result['intent']);
        $this->assertSame('delivery', data_get($result, 'draft_order.fulfillment'));
        $this->assertStringNotContainsString('qual é o endereço', mb_strtolower($result['suggested_reply']));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    private function providerMustNotRun(): ConversationCopilotProviderInterface
    {
        return new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'not-called';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for backend-grounded conversational policy.');
            }
        };
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

    private function configureOfficialHours(Company $company): void
    {
        foreach (range(0, 6) as $weekday) {
            $open = $weekday !== 0;
            $company->operatingHours()->updateOrCreate(
                ['weekday' => $weekday],
                ['is_open' => $open, 'opens_at' => $open ? '10:30' : null, 'closes_at' => $open ? '14:00' : null],
            );
        }
    }

    private function conversation(Company $company): Conversation
    {
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de teste']);

        return Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
    }
}
