<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Jobs\ProcessCopilotAutomation;
use App\Models\AiAutomationSetting;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\DeliveryQuote;
use App\Models\DeliverySetting;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WhatsAppMessageDelivery;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationService;
use App\Services\Ai\CopilotAutomationSettings;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Conversations\ConversationWorkflowService;
use App\Services\Delivery\DeliveryRoutingService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CopilotAutomationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_mode_is_an_absolute_blocker(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_MANUAL])->save();
        $this->enableActSafe($company);
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldNotReceive('analyze');
        $this->app->instance(ConversationCopilotService::class, $copilot);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, data_get($event?->payload, 'decision'));
        $this->assertFalse((bool) $event?->requires_human_confirmation);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_disabled_rollout_short_circuits_the_provider_and_records_a_minimal_audit_event(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual Ã© o cardÃ¡pio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company, CopilotAutomationAuthorityPolicy::ROLLOUT_DISABLED);
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldNotReceive('analyze');
        $this->app->instance(ConversationCopilotService::class, $copilot);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_DISABLED, data_get($event?->payload, 'decision'));
        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertContains('company_rollout_disabled', data_get($event?->payload, 'reason_codes', []));
        $this->assertFalse((bool) $event?->requires_human_confirmation);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_inbound_flow_queues_copilot_after_message_persistence(): void
    {
        Queue::fake();
        [, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');

        app(ConversationAiService::class)->considerIncomingMessage(
            $conversation->company()->firstOrFail(),
            $conversation,
            $message,
            (int) $conversation->automation_version,
        );

        Queue::assertPushed(ProcessCopilotAutomation::class, fn (ProcessCopilotAutomation $job): bool => $job->messageId === $message->id
            && $job->expectedAutomationVersion === (int) $conversation->automation_version);
    }

    public function test_shadow_rollout_records_its_decision_without_sending_or_mutating(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($this->safeReplyAnalysis()));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, data_get($event?->payload, 'decision'));
        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertSame('MENU_REQUEST', data_get($event?->payload, 'intent'));
        $this->assertSame('requires_human_review', data_get($event?->payload, 'safe_result_status'));
        $this->assertTrue((bool) data_get($event?->payload, 'guard_results.requires_human_review'));
        $this->assertFalse((bool) data_get($event?->payload, 'guard_results.policy_requires_human_review'));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_shadow_event_records_sanitized_warning_and_missing_information_codes(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('pedido de teste');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);
        $analysis = [
            'intent' => 'ORDER_CREATE',
            'suggested_reply' => 'Resumo do pedido.',
            'draft_order' => ['fulfillment' => 'pickup', 'items' => []],
            'missing_information' => [['code' => 'CARNE', 'label' => 'Carnes']],
            'warnings' => [['code' => 'UNRESOLVED_MEAT', 'message' => 'Uma carne sugerida nao pertence de forma inequivoca ao produto.']],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ];
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($analysis));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(['UNRESOLVED_MEAT'], data_get($event?->payload, 'guard_results.warning_codes'));
        $this->assertSame(['CARNE'], data_get($event?->payload, 'guard_results.missing_information_codes'));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_shadow_safe_clarification_records_context_while_preserving_the_unresolved_order_diagnostics(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('Quero uma N8 de 16 com frango.');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);
        $product = $this->availableProduct($company);
        $analysis = [
            'intent' => 'ORDER_CREATE',
            'draft_order' => ['items' => [['menu_item_id' => $product->id, 'menu_item_slug' => $product->slug, 'quantity' => 1]], 'fulfillment' => 'pickup'],
            'missing_information' => [['code' => 'CARNE', 'label' => 'Carnes']],
            'warnings' => [
                ['code' => 'AMBIGUOUS_MEAT'],
                ['code' => 'DOMAIN_SELECTION_REJECTED'],
            ],
            'clarification' => [
                'type' => 'MEAT',
                'source' => 'DAILY_MENU',
                'grounded' => true,
                'options' => [
                    ['component_id' => 701, 'display_name' => 'Opção que não deve ser persistida'],
                    ['component_id' => 702, 'display_name' => 'Outra opção que não deve ser persistida'],
                ],
                'scope' => ['product_id' => $product->id, 'selection_group' => 'meat'],
            ],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ];
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($analysis));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $context = (array) data_get($event?->payload, 'clarification_context');
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, data_get($event?->payload, 'decision'));
        $this->assertSame('send_safe_clarification', data_get($event?->payload, 'action'));
        $this->assertSame('requires_human_review', data_get($event?->payload, 'safe_result_status'));
        $this->assertTrue((bool) data_get($event?->payload, 'guard_results.requires_human_review'));
        $this->assertFalse((bool) data_get($event?->payload, 'guard_results.policy_requires_human_review'));
        $this->assertSame(['CARNE'], data_get($event?->payload, 'guard_results.missing_information_codes'));
        $this->assertSame(['AMBIGUOUS_MEAT', 'DOMAIN_SELECTION_REJECTED'], data_get($event?->payload, 'guard_results.warning_codes'));
        $this->assertSame('ambiguous_meat', data_get($context, 'type'));
        $this->assertSame([$product->id, $product->slug, 1], [data_get($context, 'candidate.product_id'), data_get($context, 'candidate.product_slug'), data_get($context, 'candidate.quantity')]);
        $this->assertSame([701, 702], $context['option_component_ids']);
        $this->assertStringNotContainsString('Opção que não deve ser persistida', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_act_safe_sends_one_grounded_clarification_without_mutating_orders_or_payments(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('Quero uma N8 de 16 com uma carne.');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $product = $this->availableProduct($company);
        $analysis = [
            'intent' => 'ORDER_CREATE',
            'suggested_reply' => 'Qual carne disponivel hoje voce prefere?',
            'draft_order' => ['items' => [['menu_item_id' => $product->id, 'menu_item_slug' => $product->slug, 'quantity' => 1]], 'fulfillment' => 'pickup'],
            'missing_information' => [['code' => 'CARNE', 'label' => 'Carnes']],
            'warnings' => [
                ['code' => 'AMBIGUOUS_MEAT'],
                ['code' => 'DOMAIN_SELECTION_REJECTED'],
            ],
            'clarification' => [
                'type' => 'MEAT',
                'source' => 'DAILY_MENU',
                'grounded' => true,
                'options' => [
                    ['component_id' => 701, 'display_name' => 'Frango'],
                    ['component_id' => 702, 'display_name' => 'Porco'],
                ],
                'scope' => ['product_id' => $product->id, 'selection_group' => 'meat'],
            ],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ];
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($analysis));

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($first?->payload, 'decision'));
        $this->assertSame('send_safe_clarification', data_get($first?->payload, 'action'));
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(AutomationEvent::STATUS_DISPATCHED, $first?->fresh()->status);
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame($message->id, Message::query()->where('direction', 'outbound')->firstOrFail()->reply_to_message_id);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_marmita_price_follow_up_keeps_the_pending_components_without_handoff(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 15:00:00');

        try {
            [$company, $conversation, $ingredients] = $this->conversationWithInbound(
                'arroz, feijao, batata doce, repolho alho e oleo e peixe empanado',
            );
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $this->configureOfficialHours($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $this->enableActSafe($company);
            $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
            {
                public function name(): string
                {
                    return 'not-called';
                }

                public function analyze(array $context): array
                {
                    throw new \LogicException('Provider must not be called for a grounded menu price follow-up.');
                }
            });

            $clarification = app(CopilotAutomationService::class)->handle(
                $ingredients->id,
                (int) $conversation->automation_version,
            );
            $clarificationRetry = app(CopilotAutomationService::class)->handle(
                $ingredients->id,
                (int) $conversation->automation_version,
            );
            $priceQuestion = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'sender_type' => 'customer',
                'direction' => 'inbound',
                'content' => 'quais os valores das marmitas?',
                'type' => 'text',
                'provider' => 'fake',
                'external_message_id' => 'wamid.marmita-prices.'.uniqid(),
                'received_at' => now(),
            ]);
            $prices = app(CopilotAutomationService::class)->handle(
                $priceQuestion->id,
                (int) $conversation->automation_version,
            );

            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($clarification?->payload, 'decision'));
            $this->assertSame($clarification?->id, $clarificationRetry?->id);
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($prices?->payload, 'decision'));
            $this->assertSame('PRODUCT_CLARIFICATION', data_get($prices?->payload, 'intent'));
            $this->assertFalse((bool) $prices?->requires_human_confirmation);
            $outbound = Message::query()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->orderBy('id')->get();
            $n5Price = Product::query()->where('company_id', $company->id)->where('menu_rule_code', 'n5_casa')->value('base_price_cents');
            $canonicalN5Price = 'R$ '.number_format(((int) $n5Price) / 100, 2, ',', '.');
            $this->assertGreaterThanOrEqual(3, $outbound->count());
            $this->assertLessThanOrEqual(6, $outbound->count());
            $allReplies = $outbound->pluck('content')->implode("\n");
            $this->assertStringContainsString('Entendi:', $allReplies);
            $this->assertStringContainsString('qual marmitex', mb_strtolower($allReplies));
            $this->assertStringContainsString('valores das marmitas', mb_strtolower($allReplies));
            $this->assertStringContainsString($canonicalN5Price, $allReplies);
            $this->assertSame(0, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->count());
            $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());
            $this->assertSame('product_selection', data_get($context, 'pending_clarification.type'), json_encode([
                'clarification' => $clarification?->fresh()->payload,
                'prices' => $prices?->fresh()->payload,
                'pending' => data_get($context, 'pending_clarification'),
            ], JSON_PRETTY_PRINT));
            $this->assertSame('automation_event', data_get($context, 'pending_order_state.source'));
            $this->assertGreaterThanOrEqual(3, count((array) data_get($context, 'pending_order_state.selected_components')), json_encode([
                'clarification' => $clarification?->fresh()->payload,
                'prices' => $prices?->fresh()->payload,
                'pending' => data_get($context, 'pending_clarification'),
                'order_state' => data_get($context, 'pending_order_state'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_act_safe_asks_for_the_configured_n5_meat_once_instead_of_opening_generic_review(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('quero uma N5 da casa mesmo');
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $n5 = Product::query()->where('company_id', $company->id)->where('menu_rule_code', 'n5_casa')->firstOrFail();
        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
            'intent' => 'ORDER_CREATE',
            'confidence' => 0.99,
            'draft_order' => ['items' => [['menu_item_id' => $n5->id, 'menu_item_slug' => $n5->slug, 'quantity' => 1, 'selections' => []]], 'fulfillment' => 'pickup'],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => 'Qual carne você prefere?',
        ]));

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($first?->payload, 'decision'), json_encode($first?->payload, JSON_PRETTY_PRINT));
        $outbound = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('send_safe_clarification', data_get($first?->payload, 'action'));
        $this->assertSame('PRODUCT_CONFIGURATION', data_get($first?->payload, 'clarification_context.source'));
        $this->assertSame($first?->id, $second?->id);
        $this->assertStringContainsString('Qual carne você prefere?', $outbound->content);
        $this->assertStringContainsString('Porco', $outbound->content);
        $this->assertSame(count((array) data_get($first?->payload, 'reply_messages')), Message::query()->where('direction', 'outbound')->count());
        $this->assertLessThanOrEqual(3, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_act_safe_resolves_the_configured_n5_meat_and_continues_without_human_review(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('quero uma N5 da casa');
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $n5 = Product::query()->where('company_id', $company->id)->where('menu_rule_code', 'n5_casa')->firstOrFail();
        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
            'intent' => 'ORDER_CREATE',
            'confidence' => 0.99,
            'draft_order' => ['items' => [['menu_item_id' => $n5->id, 'menu_item_slug' => $n5->slug, 'quantity' => 1, 'selections' => []]], 'fulfillment' => null],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => 'Qual carne você prefere?',
        ]));

        $clarification = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        DeliverySetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['provider_options' => ['origin' => ['address' => 'Rua Configurada, 123', 'latitude' => -16.0, 'longitude' => -49.0]]],
        );
        $locationQuestion = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'onde fica o restaurante?',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.n5-location.'.uniqid(),
            'received_at' => now(),
        ]);
        $location = app(CopilotAutomationService::class)->handle($locationQuestion->id, (int) $conversation->automation_version);
        app(ConversationAlertService::class)->open(
            company: $company,
            type: ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: 'Revisão necessária — Cliente de teste',
            message: 'N5 Casa precisa de confirmação: falta escolher a carne.',
            conversation: $conversation,
            messageModel: $message,
            deduplicationKey: 'copilot-act-safe-review:'.$conversation->id.':missing-meat',
        );
        $conversation->forceFill(['human_review_required' => true])->save();
        $reply = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'pode ser porco',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.n5-meat.'.uniqid(),
            'received_at' => now(),
        ]);

        $resolved = app(CopilotAutomationService::class)->handle($reply->id, (int) $conversation->automation_version);
        $retried = app(CopilotAutomationService::class)->handle($reply->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($clarification?->payload, 'decision'), json_encode($clarification?->payload, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) data_get($clarification?->payload, 'waiting_for_customer'));
        $this->assertSame('LOCATION_REQUEST', data_get($location?->payload, 'intent'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($location?->payload, 'decision'));
        $this->assertStringContainsString('Rua Configurada, 123', Message::query()->where('reply_to_message_id', $locationQuestion->id)->firstOrFail()->content);
        $this->assertSame('ORDER_CONTINUE', data_get($resolved?->payload, 'intent'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($resolved?->payload, 'decision'), json_encode($resolved?->payload, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) $resolved?->requires_human_confirmation);
        $this->assertSame('send_grounded_reply', data_get($resolved?->payload, 'action'));
        $this->assertSame(['item_confirmation_required'], data_get($resolved?->payload, 'reason_codes'));
        $this->assertSame('resolved', data_get($resolved?->payload, 'clarification_resolution'));
        $this->assertSame([], data_get($resolved?->payload, 'guard_results.missing_information_codes'));
        $this->assertSame([], data_get($resolved?->payload, 'guard_results.warning_codes'));
        $this->assertTrue((bool) data_get($resolved?->payload, 'waiting_for_customer'));
        $this->assertSame($resolved?->id, $retried?->id);
        $resolvedReplies = Message::query()->where('direction', 'outbound')->where('reply_to_message_id', $reply->id)->orderBy('id')->get();
        $this->assertCount(count((array) data_get($resolved?->payload, 'reply_messages')), $resolvedReplies);
        $this->assertStringContainsString('Porco', $resolvedReplies->pluck('content')->implode("\n"));
        $this->assertStringContainsString('Está tudo certo?', $resolvedReplies->pluck('content')->implode("\n"));
        $this->assertSame('CONFIRM_ITEM', data_get($resolved?->payload, 'order_context.next_objective'));
        $this->assertFalse($conversation->fresh()->human_review_required);
        $this->assertSame(ConversationAlert::STATUS_RESOLVED, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->firstOrFail()->status);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_pending_human_review_still_allows_grounded_location_reply(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('onde fica o restaurante?');
        $conversation->forceFill([
            'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
            'human_review_required' => true,
        ])->save();
        DeliverySetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['provider_options' => ['origin' => ['address' => 'Rua Configurada, 123', 'latitude' => -16.0, 'longitude' => -49.0]]],
        );
        $this->enableActSafe($company);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $outbound = Message::query()->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($event?->payload, 'decision'));
        $this->assertSame('send_grounded_reply', data_get($event?->payload, 'action'));
        $this->assertStringContainsString('Rua Configurada, 123', $outbound->content);
        $this->assertTrue($conversation->fresh()->human_review_required);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_safe_new_turn_keeps_a_low_confidence_review_when_its_cause_cannot_be_proven_resolved(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('boa tarde');
        $conversation->forceFill([
            'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
            'human_review_required' => true,
        ])->save();
        $this->enableActSafe($company);
        $stale = app(ConversationAlertService::class)->open(
            company: $company,
            type: ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: 'Revisão antiga',
            conversation: $conversation,
            deduplicationKey: 'copilot-act-safe-review:'.$conversation->id.':general',
            metadata: ['reason_codes' => ['ambiguous_or_unsupported_request']],
        );
        $protected = app(ConversationAlertService::class)->open(
            company: $company,
            type: ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: 'Atendimento humano solicitado',
            conversation: $conversation,
            deduplicationKey: 'copilot-act-safe-review:'.$conversation->id.':customer-requested-human',
            metadata: ['reason_codes' => ['handoff_customer_requested_human']],
        );
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning([
            'intent' => 'GREETING',
            'suggested_reply' => 'Boa tarde! Como posso ajudar?',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ]));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($event?->payload, 'decision'));
        $this->assertSame(ConversationAlert::STATUS_OPEN, $stale->fresh()->status);
        $this->assertSame(ConversationAlert::STATUS_OPEN, $protected->fresh()->status);
        $this->assertTrue($conversation->fresh()->human_review_required);
        $this->assertSame(1, Message::query()->where('reply_to_message_id', $message->id)->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_repeated_ordinary_missing_meat_remains_a_clarification_without_review_alert(): void
    {
        [$company, $conversation, $firstMessage] = $this->conversationWithInbound('pedido incompleto');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $analysis = [
            'intent' => 'ORDER_CREATE',
            'suggested_reply' => 'Qual carne você deseja?',
            'draft_order' => ['items' => [], 'fulfillment' => 'pickup'],
            'missing_information' => [['code' => 'CARNE']],
            'warnings' => [['code' => 'UNRESOLVED_MEAT']],
            'proposal' => ['items' => [['product_name' => 'N5 Casa']], 'target' => ['state' => 'NEW_ORDER']],
        ];
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldReceive('analyze')->twice()->andReturn($analysis);
        $this->app->instance(ConversationCopilotService::class, $copilot);

        app(CopilotAutomationService::class)->handle($firstMessage->id, (int) $conversation->automation_version);
        $secondMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'ainda não sei a carne',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.review.'.uniqid(),
            'received_at' => now(),
        ]);
        app(CopilotAutomationService::class)->handle($secondMessage->id, (int) $conversation->automation_version);

        $events = AutomationEvent::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (AutomationEvent $event): bool => data_get($event->payload, 'decision') === CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY));
        $this->assertSame(0, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->count());
        $this->assertFalse($conversation->fresh()->human_review_required);
        $this->assertSame(2, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_switching_from_manual_to_automatic_enables_the_next_inbound_shadow_analysis(): void
    {
        [$company, $conversation] = $this->conversationWithInbound('mensagem anterior');
        $this->enableActSafe($company, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);

        $workflow = app(ConversationWorkflowService::class);
        $conversation = $workflow->switchMode($conversation, Conversation::AUTOMATION_MODE_MANUAL);
        $conversation = $workflow->switchMode($conversation, Conversation::AUTOMATION_MODE_AUTOMATIC);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'qual é o cardápio?',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.shadow-after-toggle.'.uniqid(),
            'received_at' => now(),
        ]);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($this->safeReplyAnalysis()));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(Conversation::AUTOMATION_MODE_AUTOMATIC, $conversation->fresh()->automation_mode);
        $this->assertSame(CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, data_get($event?->payload, 'rollout'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, data_get($event?->payload, 'decision'));
        $this->assertSame('MENU_REQUEST', data_get($event?->payload, 'intent'));
        $this->assertNotContains('conversation_manual_mode', data_get($event?->payload, 'reason_codes'));
        $this->assertNotContains('conversation_not_automatic', data_get($event?->payload, 'reason_codes'));
        $this->assertSame('not_executed', data_get($event?->response_payload, 'execution_result'));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_act_safe_sends_deduplicated_grounded_menu_messages_with_the_fake_provider(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->availableProduct($company);
        $this->enableActSafe($company);

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($first?->payload, 'decision'));
        $this->assertSame($first?->id, $second?->id);
        $outbound = Message::query()->where('direction', 'outbound')->orderBy('id')->get();
        $this->assertCount(count((array) data_get($first?->payload, 'reply_messages')), $outbound);
        $this->assertLessThanOrEqual(3, $outbound->count());
        $this->assertTrue($outbound->contains(fn (Message $sent): bool => str_contains($sent->content, "\n\n")));
        $this->assertTrue($outbound->every(fn (Message $sent): bool => ! str_contains($sent->content, '\\n')));
        $this->assertSame(AutomationEvent::STATUS_DISPATCHED, $first?->fresh()->status);
    }

    public function test_act_safe_handles_a_common_greeting_without_low_confidence_review(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('opa bom dia');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'not-called';
            }

            public function analyze(array $context): array
            {
                throw new \LogicException('Provider must not be called for a deterministic greeting.');
            }
        });

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame('GREETING', data_get($event?->payload, 'intent'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($event?->payload, 'decision'));
        $this->assertFalse((bool) $event?->requires_human_confirmation);
        $this->assertFalse($conversation->fresh()->human_review_required);
        $this->assertSame(0, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->count());
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_real_automation_path_preserves_exact_product_follow_up_sequence_without_review_or_mutation(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');

        try {
            [$company, $conversation, $greetingMessage] = $this->conversationWithInbound('opa bom dia');
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $this->enableActSafe($company);
            $send = function (string $body) use ($conversation): Message {
                return Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'customer',
                    'sender_type' => 'customer',
                    'direction' => 'inbound',
                    'content' => $body,
                    'type' => 'text',
                    'provider' => 'fake',
                    'external_message_id' => 'wamid.contextual-sequence.'.uniqid(),
                    'received_at' => now(),
                ]);
            };

            $greeting = app(CopilotAutomationService::class)->handle($greetingMessage->id, (int) $conversation->automation_version);
            $discoveryMessage = $send('quero pedir uma marmita');
            $discovery = app(CopilotAutomationService::class)->handle($discoveryMessage->id, (int) $conversation->automation_version);

            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider($this->semanticFixture('PRODUCT_CLARIFICATION', [
                'intents' => ['ask_product_information'], 'subject' => 'product', 'target_products' => ['n8-tradicional'],
                'reference' => 'recent_options', 'mutates_order' => false, 'facts_needed' => ['product_details'], 'reply_goal' => 'explain_product_and_continue',
            ])));
            $livreMessage = $send('como funciona essa N8 Livre?');
            $livre = app(CopilotAutomationService::class)->handle($livreMessage->id, (int) $conversation->automation_version);

            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider($this->semanticFixture('PRODUCT_CLARIFICATION', [
                'intents' => ['ask_product_information'], 'subject' => 'product', 'target_products' => ['n8-casa'],
                'reference' => 'active_references', 'mutates_order' => false, 'facts_needed' => ['product_details'], 'reply_goal' => 'explain_product_and_continue',
            ])));
            $casaMessage = $send('e a N8 Casa?');
            $casa = app(CopilotAutomationService::class)->handle($casaMessage->id, (int) $conversation->automation_version);

            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider($this->semanticFixture('PRODUCT_CLARIFICATION', [
                'intents' => ['compare_products'], 'subject' => 'product', 'target_products' => [],
                'reference' => 'active_references', 'mutates_order' => false, 'facts_needed' => ['product_details'], 'reply_goal' => 'compare_and_continue',
            ])));
            $comparisonMessage = $send('qual a diferença das duas?');
            $comparison = app(CopilotAutomationService::class)->handle($comparisonMessage->id, (int) $conversation->automation_version);

            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider($this->semanticFixture('ORDER_CONTINUE', [
                'intents' => ['select_option'], 'subject' => 'product', 'candidate_value' => 'Livre', 'target_products' => ['n8-tradicional'],
                'reference' => 'active_references', 'mutates_order' => true, 'facts_needed' => [], 'reply_goal' => 'continue_order',
            ])));
            $selectionMessage = $send('pode ser a livre');
            $selection = app(CopilotAutomationService::class)->handle($selectionMessage->id, (int) $conversation->automation_version);
            $buffetMessage = $send('qual buffet hoje?');
            $buffet = app(CopilotAutomationService::class)->handle($buffetMessage->id, (int) $conversation->automation_version);

            foreach ([$greeting, $discovery, $livre, $casa, $comparison, $selection, $buffet] as $event) {
                $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($event?->payload, 'decision'), json_encode($event?->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->assertFalse((bool) $event?->requires_human_confirmation);
            }
            $this->assertSame('PRODUCT_CLARIFICATION', data_get($livre?->payload, 'intent'));
            $this->assertStringNotContainsStringIgnoringCase('horário', implode("\n", data_get($livre?->payload, 'reply_messages', [])));
            $this->assertStringContainsString('N8 Casa', implode("\n", data_get($comparison?->payload, 'reply_messages', [])));
            $this->assertStringContainsString('N8 Livre', implode("\n", data_get($comparison?->payload, 'reply_messages', [])));
            $this->assertSame('n8-tradicional', data_get($selection?->payload, 'order_context.draft_order.items.0.menu_item_slug'));
            $this->assertStringContainsString('Buffet de hoje', implode("\n", data_get($buffet?->payload, 'reply_messages', [])));
            $outboundCount = Message::query()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count();
            $this->assertGreaterThanOrEqual(7, $outboundCount);
            $this->assertLessThanOrEqual(21, $outboundCount);
            $this->assertSame(0, ConversationAlert::query()->where('conversation_id', $conversation->id)->currentActionable()->count());
            $this->assertFalse($conversation->fresh()->human_review_required);
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_closed_sunday_menu_reply_is_deduplicated_without_review_order_or_payment(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-30 12:00:00', 'America/Sao_Paulo'));
        try {
            [$company, $conversation, $message] = $this->conversationWithInbound('Qual o cardápio de hoje?');
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $this->configureOfficialHours($company);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $this->enableActSafe($company);

            $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
            $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($first?->payload, 'decision'));
            $this->assertSame('CLOSED', data_get($first?->payload, 'operational_status'));
            $this->assertSame($first?->id, $second?->id);
            $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
            $this->assertStringContainsString('Hoje estamos fechados', Message::query()->where('direction', 'outbound')->firstOrFail()->content);
            $this->assertSame(0, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->count());
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_act_safe_sends_one_safe_general_reply_without_operational_mutation(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('Oi');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $this->app->instance(
            ConversationCopilotProviderInterface::class,
            new FakeConversationCopilotProvider($this->generalReplyAnalysis()),
        );

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($first?->payload, 'decision'));
        $this->assertSame('send_grounded_reply', data_get($first?->payload, 'action'));
        $this->assertContains('safe_conversational_reply', data_get($first?->payload, 'reason_codes', []));
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_shadow_general_reply_remains_observation_only(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('Boa tarde');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);
        $this->app->instance(
            ConversationCopilotProviderInterface::class,
            new FakeConversationCopilotProvider($this->generalReplyAnalysis()),
        );

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, data_get($event?->payload, 'decision'));
        $this->assertSame('send_grounded_reply', data_get($event?->payload, 'action'));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_act_safe_requires_item_confirmation_before_staging_a_validated_order(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('quero um suco teste');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Bebidas',
            'slug' => 'bebidas',
        ]);
        Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Suco Teste',
            'slug' => 'suco-teste',
            'product_type' => 'beverage',
            'base_price_cents' => 1234,
            'currency' => 'BRL',
            'is_active' => true,
            'is_available_by_default' => true,
        ]);
        $this->enableActSafe($company);
        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
            'intent' => 'ORDER_CREATE',
            'confidence' => 0.99,
            'draft_order' => ['items' => [['product' => 'suco teste', 'quantity' => 1, 'unit_price_cents' => 1]], 'fulfillment' => 'pickup'],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => 'Entendi: 1 Suco Teste. Confere?',
        ]));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($event?->payload, 'decision'));
        $this->assertSame('CONFIRM_ITEM', data_get($event?->payload, 'order_context.next_objective'));
        $this->assertStringContainsString('R$ 12,34', implode("\n", (array) data_get($event?->payload, 'reply_messages')));
        $this->assertSame(0, Order::count());
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_payment_requests_are_denied_from_automatic_execution(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('paguei no pix, confirma para mim?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_DENIED_AUTO, data_get($event?->payload, 'decision'));
        $this->assertTrue($event?->requires_human_confirmation);
        $this->assertSame('send_payment_confirmation_notice', data_get($event?->payload, 'action'));
        $notice = Message::query()->where('direction', 'outbound')->firstOrFail()->content;
        $this->assertStringContainsString('não confirmo pagamentos', $notice);
        $this->assertStringContainsString('comprovante', $notice);
        $this->assertStringContainsString('equipe fará a conferência', $notice);
        $this->assertSame(0, Order::count());
    }

    public function test_newer_inbound_message_aborts_a_stale_decision_without_effects(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldReceive('analyze')->once()->andReturnUsing(function () use ($conversation): array {
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'sender_type' => 'customer',
                'direction' => 'inbound',
                'content' => 'uma mensagem mais nova',
                'type' => 'text',
                'provider' => 'fake',
                'external_message_id' => 'wamid.newer.'.uniqid(),
                'received_at' => now(),
            ]);

            return $this->safeReplyAnalysis();
        });
        $this->app->instance(ConversationCopilotService::class, $copilot);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertContains('execution_gate_changed', data_get($event?->payload, 'reason_codes', []));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_newer_inbound_between_multipart_parts_discards_the_remaining_stale_reply(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('oi');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $sent = new WhatsAppMessageDelivery(['status' => WhatsAppMessageDelivery::STATUS_SENT]);
        $sent->id = 7001;
        $sent->message_id = 8001;
        $whatsapp = Mockery::mock(WhatsAppService::class);
        $whatsapp->shouldReceive('sendTextMessage')->once()->andReturnUsing(function () use ($conversation, $sent): WhatsAppMessageDelivery {
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'sender_type' => 'customer',
                'direction' => 'inbound',
                'content' => 'quero uma N8 Livre',
                'type' => 'text',
                'provider' => 'fake',
                'external_message_id' => 'wamid.turn-b',
                'received_at' => now()->addSecond(),
            ]);

            return $sent;
        });
        $this->app->instance(WhatsAppService::class, $whatsapp);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning([
            ...$this->safeReplyAnalysis(),
            'suggested_reply' => 'Saudação antiga. Segunda parte antiga.',
            'reply_messages' => ['Saudação antiga.', 'Segunda parte antiga.'],
        ]));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertContains('stale_outbound_discarded', data_get($event?->payload, 'reason_codes', []));
        $this->assertTrue((bool) data_get($event?->payload, 'turn_trace.stale_discarded'));
        $this->assertSame('discard_stale_turn', data_get($event?->payload, 'turn_trace.next_action'));
    }

    public function test_retry_never_resurrects_a_reply_from_an_older_turn(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('oi');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $event = AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => 'openai',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_FAILED,
            'payload' => [
                'decision' => CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY,
                'reply_messages' => ['Oi! 😊', 'Como posso ajudar?'],
                'turn_trace' => ['turn_id' => 'turn-a', 'stale_discarded' => false],
            ],
            'response_payload' => ['execution_result' => 'outbound_failed'],
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'quero uma N8 Livre',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.turn-b-retry',
            'received_at' => now()->addSecond(),
        ]);
        $whatsapp = Mockery::mock(WhatsAppService::class);
        $whatsapp->shouldNotReceive('sendTextMessage');
        $whatsapp->shouldNotReceive('retryTextMessage');
        $this->app->instance(WhatsAppService::class, $whatsapp);

        $retry = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame($event->id, $retry?->id);
        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $retry?->status);
        $this->assertContains('stale_retry_discarded', data_get($retry?->payload, 'reason_codes', []));
        $this->assertTrue((bool) data_get($retry?->payload, 'turn_trace.stale_discarded'));
    }

    public function test_late_webhook_with_older_provider_timestamp_is_not_the_current_turn(): void
    {
        [$company, $conversation, $newer] = $this->conversationWithInbound('quero uma N8 Livre');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $newer->forceFill(['received_at' => now()])->save();
        $older = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'oi',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.delayed-old',
            'received_at' => now()->subMinute(),
        ]);

        $event = app(CopilotAutomationService::class)->handle($older->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertContains('stale_inbound_message', data_get($event?->payload, 'reason_codes', []));
        $this->assertSame(['wamid.delayed-old'], data_get($event?->payload, 'trigger_message_ids'));
        $this->assertSame([$older->id], data_get($event?->payload, 'turn_trace.trigger_message_record_ids'));
        $this->assertTrue((bool) data_get($event?->payload, 'turn_trace.stale_discarded'));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_manual_takeover_during_analysis_aborts_the_pending_effect(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldReceive('analyze')->once()->andReturnUsing(function () use ($conversation): array {
            $conversation->forceFill([
                'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
                'automation_version' => (int) $conversation->automation_version + 1,
            ])->save();

            return $this->safeReplyAnalysis();
        });
        $this->app->instance(ConversationCopilotService::class, $copilot);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_rollout_revocation_after_policy_decision_aborts_the_pending_effect(): void
    {
        $this->assertRolloutRevocationAborts(CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);
        $this->assertRolloutRevocationAborts(CopilotAutomationAuthorityPolicy::ROLLOUT_DISABLED);
    }

    private function assertRolloutRevocationAborts(string $revokedTo): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $this->app->instance(CopilotAutomationSettings::class, new class($company, $revokedTo) extends CopilotAutomationSettings
        {
            private int $lookups = 0;

            public function __construct(private readonly Company $company, private readonly string $revokedTo) {}

            public function rolloutFor(Company $company): string
            {
                $this->lookups++;
                if ($this->lookups === 2) {
                    AiAutomationSetting::query()
                        ->where('company_id', $this->company->id)
                        ->where('provider', self::PROVIDER)
                        ->update(['settings' => ['rollout' => $this->revokedTo]]);

                    return CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE;
                }

                return parent::rolloutFor($company);
            }
        });

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertContains('execution_gate_changed', data_get($event?->payload, 'reason_codes', []));
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_active_order_change_during_analysis_aborts_a_new_order_action(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('quero um suco teste');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $product = $this->availableProduct($company);
        $this->enableActSafe($company);
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldReceive('analyze')->once()->andReturnUsing(function () use ($company, $conversation, $product): array {
            $activeOrder = app(OrderWorkflowService::class)->createDraft($company, ['conversation_id' => $conversation->id]);
            $conversation->forceFill(['active_order_id' => $activeOrder->id])->save();

            return $this->readyOrderAnalysis($product);
        });
        $this->app->instance(ConversationCopilotService::class, $copilot);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $event?->status);
        $this->assertContains('execution_gate_changed', data_get($event?->payload, 'reason_codes', []));
        $this->assertSame(1, Order::count());
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_outbound_failure_does_not_duplicate_a_staged_order_on_retry(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('quero um suco teste');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $product = $this->availableProduct($company);
        $this->enableActSafe($company);
        $delivery = new WhatsAppMessageDelivery(['status' => WhatsAppMessageDelivery::STATUS_FAILED]);
        $delivery->id = 999;
        $whatsapp = Mockery::mock(WhatsAppService::class);
        $whatsapp->shouldReceive('sendTextMessage')->twice()->withArgs(function (Company $sentCompany, string $to, string $body, array $attributes) use ($company, $message): bool {
            return $sentCompany->is($company)
                && $to !== ''
                && $body !== ''
                && str_starts_with($attributes['client_reference'], 'copilot-act-safe:v1:inbound:'.$message->id);
        })->andReturn($delivery);
        $this->app->instance(WhatsAppService::class, $whatsapp);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($this->readyOrderAnalysis($product)));

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(AutomationEvent::STATUS_FAILED, $first?->fresh()->status);
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Order::query()->firstOrFail()->items()->count());
        $this->assertSame('outbound_failed', data_get($first?->fresh()->response_payload, 'execution_result'));
    }

    public function test_multi_message_retry_keeps_order_and_does_not_repeat_the_first_part(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('pedido em duas mensagens');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $sent = new WhatsAppMessageDelivery(['status' => WhatsAppMessageDelivery::STATUS_SENT]);
        $sent->id = 901;
        $sent->message_id = 801;
        $failed = new WhatsAppMessageDelivery(['status' => WhatsAppMessageDelivery::STATUS_FAILED]);
        $failed->id = 902;
        $failedMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'agent',
            'sender_type' => 'ai',
            'direction' => 'outbound',
            'content' => 'Segunda parte.',
            'type' => 'text',
            'provider' => 'fake',
            'delivery_status' => WhatsAppMessageDelivery::STATUS_FAILED,
        ]);
        $failed->message_id = $failedMessage->id;
        $retried = new WhatsAppMessageDelivery(['status' => WhatsAppMessageDelivery::STATUS_SENT]);
        $retried->id = 903;
        $retried->message_id = $failedMessage->id;
        $whatsapp = Mockery::mock(WhatsAppService::class);
        $whatsapp->shouldReceive('sendTextMessage')->once()->ordered()->withArgs(
            fn (Company $sentCompany, string $to, string $body, array $attributes): bool => $sentCompany->is($company)
                && $body === 'Primeira parte.'
                && str_ends_with($attributes['client_reference'], ':part:1'),
        )->andReturn($sent);
        $whatsapp->shouldReceive('sendTextMessage')->once()->ordered()->withArgs(
            fn (Company $sentCompany, string $to, string $body, array $attributes): bool => $sentCompany->is($company)
                && $body === 'Segunda parte.'
                && str_ends_with($attributes['client_reference'], ':part:2'),
        )->andReturn($failed);
        $whatsapp->shouldReceive('retryTextMessage')->once()->ordered()->withArgs(
            fn (Company $sentCompany, Message $retryMessage): bool => $sentCompany->is($company)
                && $retryMessage->is($failedMessage),
        )->andReturn($retried);
        $this->app->instance(WhatsAppService::class, $whatsapp);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning([
            'intent' => 'GENERAL_MESSAGE',
            'suggested_reply' => 'Primeira parte. Segunda parte.',
            'reply_messages' => ['Primeira parte.', 'Segunda parte.'],
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ]));

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $retry = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame($first?->id, $retry?->id);
        $this->assertSame(AutomationEvent::STATUS_DISPATCHED, $retry?->fresh()->status);
        $this->assertSame([0, 1, 1], collect(data_get($retry?->fresh()->response_payload, 'outbound_deliveries'))->pluck('part')->all());
        $this->assertSame(['sent', 'failed', 'sent'], collect(data_get($retry?->fresh()->response_payload, 'outbound_deliveries'))->pluck('status')->all());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_validated_delivery_location_and_payment_selection_stage_one_order_without_confirming_payment(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('entrega nessa localização e pago no pix');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $product = $this->availableProduct($company);
        $this->enableActSafe($company);
        $routing = Mockery::mock(DeliveryRoutingService::class);
        $routing->shouldReceive('setCoordinates')->once()->withArgs(
            fn (Order $order, float $latitude, float $longitude): bool => $order->company_id === $company->id
                && $latitude === -16.3267
                && $longitude === -48.9528,
        )->andReturn(new DeliveryQuote);
        $this->app->instance(DeliveryRoutingService::class, $routing);
        $analysis = $this->readyOrderAnalysis($product);
        data_set($analysis, 'draft_order.fulfillment', 'delivery');
        data_set($analysis, 'draft_order.address', 'Localização compartilhada via WhatsApp');
        data_set($analysis, 'draft_order.payment_method', 'pix');
        data_set($analysis, 'metadata.customer_location', ['latitude' => -16.3267, 'longitude' => -48.9528]);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($analysis));

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $retry = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION, data_get($event?->payload, 'decision'));
        $this->assertSame('stage_new_order', data_get($event?->payload, 'action'));
        $this->assertSame($event?->id, $retry?->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(Order::FULFILLMENT_DELIVERY, Order::query()->firstOrFail()->fulfillment_type);
        $this->assertSame(Order::query()->firstOrFail()->id, $conversation->fresh()->active_order_id);
        $this->assertSame(1, Payment::count());
        $payment = Payment::query()->sole();
        $this->assertSame(Payment::METHOD_PIX, $payment->method);
        $this->assertSame(Payment::STATUS_AWAITING_PROOF, $payment->status);
        $this->assertSame((int) Order::query()->firstOrFail()->total_cents, (int) $payment->amount_cents);
        $this->assertSame(0, (int) $payment->confirmed_amount_cents);
    }

    public function test_delivery_address_materializes_quoted_order_then_pix_reuses_one_pending_payment(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('entrega na Rua do Sol, 123');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $product = $this->availableProduct($company);
        $this->enableActSafe($company);
        $routing = Mockery::mock(DeliveryRoutingService::class);
        $routing->shouldReceive('geocodeAndSetAddress')->once()->andReturnUsing(function (Order $order, string $address): DeliveryQuote {
            $order->forceFill([
                'delivery_fee_cents' => 801,
                'delivery_address_snapshot' => ['formatted_address' => $address],
            ])->save();
            app(OrderWorkflowService::class)->recalculateTotals($order);

            return new DeliveryQuote(['delivery_fee_cents' => 801]);
        });
        $this->app->instance(DeliveryRoutingService::class, $routing);
        $analysis = $this->readyOrderAnalysis($product);
        data_set($analysis, 'draft_order.fulfillment', 'delivery');
        data_set($analysis, 'draft_order.address', 'Rua do Sol, 123');
        data_set($analysis, 'draft_order.payment_method', null);
        data_set($analysis, 'missing_information', [['code' => 'PAYMENT_METHOD', 'label' => 'Forma de pagamento']]);
        data_set($analysis, 'proposal.applyability', 'PARTIAL');
        data_set($analysis, 'proposal.missing_information', [['code' => 'PAYMENT_METHOD']]);
        $this->app->instance(ConversationCopilotService::class, $this->copilotReturning($analysis));

        $quoted = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $quotedRetry = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION, data_get($quoted?->payload, 'decision'), json_encode($quoted?->payload, JSON_PRETTY_PRINT));
        $this->assertContains('payment_method_required_after_quote', data_get($quoted?->payload, 'reason_codes', []));
        $this->assertSame($quoted?->id, $quotedRetry?->id);
        $order = Order::query()->sole();
        $this->assertSame(1234, (int) $order->subtotal_cents);
        $this->assertSame(801, (int) $order->delivery_fee_cents);
        $this->assertSame(2035, (int) $order->total_cents);
        $this->assertFalse((bool) $order->human_review_required);
        $this->assertSame(0, Payment::count());
        $quoteReplies = Message::query()->where('reply_to_message_id', $message->id)->where('direction', 'outbound')->orderBy('id')->get();
        $this->assertCount(2, $quoteReplies);
        $this->assertStringContainsString('Subtotal: *R$ 12,34*', $quoteReplies[0]->content);
        $this->assertStringContainsString('Taxa de entrega: *R$ 8,01*', $quoteReplies[0]->content);
        $this->assertStringContainsString('Total: *R$ 20,35*', $quoteReplies[0]->content);
        $this->assertStringContainsString('prefere pagar', mb_strtolower($quoteReplies[1]->content));

        $this->app->forgetInstance(ConversationCopilotService::class);
        $pixMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'pix',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.pix.'.uniqid(),
            'received_at' => now(),
        ]);
        $pix = app(CopilotAutomationService::class)->handle($pixMessage->id, (int) $conversation->automation_version);
        $pixRetry = app(CopilotAutomationService::class)->handle($pixMessage->id, (int) $conversation->automation_version);

        $this->assertSame('prepare_order_payment', data_get($pix?->payload, 'action'), json_encode($pix?->payload, JSON_PRETTY_PRINT));
        $this->assertSame($pix?->id, $pixRetry?->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $payment = Payment::query()->sole();
        $this->assertSame(Payment::STATUS_AWAITING_PROOF, $payment->status);
        $this->assertSame(2035, (int) $payment->amount_cents);
        $this->assertSame(0, (int) $payment->confirmed_amount_cents);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT_PROOF, $order->fresh()->status);
    }

    public function test_real_handoff_categories_explain_the_transfer_and_retry_is_idempotent(): void
    {
        foreach ([
            'posso pagar fiado?' => ['handoff_financial_exception', 'precisa ser confirmada'],
            'consegue fazer um desconto especial?' => ['handoff_financial_exception', 'precisa ser confirmada'],
            'qual homem aranha é mais forte?' => ['handoff_out_of_domain', 'Sol Restaurante'],
        ] as $body => [$reasonCode, $expectedReply]) {
            [$company, $conversation, $message] = $this->conversationWithInbound($body);
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
            $this->enableActSafe($company);

            $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
            $retry = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

            $this->assertSame($first?->id, $retry?->id);
            $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, data_get($first?->payload, 'decision'));
            $this->assertSame('send_handoff_reply', data_get($first?->payload, 'action'));
            $this->assertContains($reasonCode, data_get($first?->payload, 'reason_codes', []));
            $this->assertStringContainsString($expectedReply, Message::query()->where('reply_to_message_id', $message->id)->firstOrFail()->content);
            $this->assertSame(1, Message::query()->where('reply_to_message_id', $message->id)->where('direction', 'outbound')->count());
            $this->assertSame(1, ConversationAlert::query()->where('conversation_id', $conversation->id)->count());
            $this->assertTrue($conversation->fresh()->human_review_required);
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_unknown_message_gets_one_clarification_before_irreparable_handoff(): void
    {
        [$company, $conversation, $firstMessage] = $this->conversationWithInbound('xpto zzz qqq');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
            'intent' => 'UNKNOWN',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => null, 'payment_method' => null],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => '',
        ]));

        $clarification = app(CopilotAutomationService::class)->handle($firstMessage->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($clarification?->payload, 'decision'));
        $this->assertContains('recoverable_unknown_clarification', data_get($clarification?->payload, 'reason_codes', []));
        $this->assertStringContainsString('não consegui entender', Message::query()->where('reply_to_message_id', $firstMessage->id)->firstOrFail()->content);
        $this->assertFalse($conversation->fresh()->human_review_required);
        $this->assertSame(0, ConversationAlert::query()->where('conversation_id', $conversation->id)->count());

        $secondMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'qqq zzz xpto',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.irreparable.'.uniqid(),
            'received_at' => now(),
        ]);

        $handoff = app(CopilotAutomationService::class)->handle($secondMessage->id, (int) $conversation->automation_version);
        $retry = app(CopilotAutomationService::class)->handle($secondMessage->id, (int) $conversation->automation_version);

        $this->assertSame($handoff?->id, $retry?->id);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, data_get($handoff?->payload, 'decision'));
        $this->assertContains('handoff_irreparable_message', data_get($handoff?->payload, 'reason_codes', []));
        $this->assertSame(1, ConversationAlert::query()->where('conversation_id', $conversation->id)->count());
        $this->assertSame(2, Message::query()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_real_burst_is_coalesced_to_the_latest_state_and_resolves_stale_missing_address_review(): void
    {
        [$company, $conversation] = $this->conversationWithInbound('quero uma marmita detalhada');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);
        $messages = Message::query()->where('conversation_id', $conversation->id)->where('direction', 'inbound')->get();
        foreach ([
            'também quero uma agua sem gas',
            'voces fazem entrega?',
            'Rua do Sol, 123',
            'quanto fica?',
            'posso pagar em dinheiro?',
        ] as $body) {
            $messages->push(Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'sender_type' => 'customer',
                'direction' => 'inbound',
                'content' => $body,
                'type' => 'text',
                'provider' => 'fake',
                'external_message_id' => 'wamid.burst.'.uniqid(),
                'received_at' => now(),
            ]));
        }
        $staleAddressAlert = app(ConversationAlertService::class)->open(
            company: $company,
            type: ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: 'Revisão necessária',
            message: 'Pedido precisa de confirmação: falta informar o endereço de entrega.',
            conversation: $conversation,
            messageModel: $messages[1],
            deduplicationKey: 'copilot-act-safe-review:'.$conversation->id.':missing-address',
            metadata: ['reason_codes' => ['normal_missing_information'], 'missing_information_codes' => ['ADDRESS']],
        );
        $product = $this->availableProduct($company);
        $analysis = $this->readyOrderAnalysis($product);
        data_set($analysis, 'draft_order.fulfillment', 'pickup');
        data_set($analysis, 'draft_order.payment_method', 'cash');
        data_set($analysis, 'draft_order.address', 'Rua do Sol, 123');
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldReceive('analyze')->once()->withArgs(function (Conversation $current) use ($messages): bool {
            $context = app(ConversationCopilotContextBuilder::class)->forConversation($current);
            $inbound = collect(data_get($context, 'messages'))->where('direction', 'inbound')->pluck('body');

            $this->assertSame($messages->pluck('content')->all(), $inbound->all());

            return true;
        })->andReturn($analysis);
        $this->app->instance(ConversationCopilotService::class, $copilot);

        $events = $messages->map(fn (Message $message): ?AutomationEvent => app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version));
        $retry = app(CopilotAutomationService::class)->handle($messages->last()->id, (int) $conversation->automation_version);

        $this->assertTrue($events->take(5)->every(fn (?AutomationEvent $event): bool => in_array('stale_inbound_message', data_get($event?->payload, 'reason_codes', []), true)));
        $this->assertSame('stage_new_order', data_get($events->last()?->payload, 'action'));
        $this->assertSame($events->last()?->id, $retry?->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(ConversationAlert::STATUS_RESOLVED, $staleAddressAlert->fresh()->status);
        $this->assertFalse($conversation->fresh()->human_review_required);
        $this->assertSame(0, ConversationAlert::query()->where('conversation_id', $conversation->id)->currentActionable()->count());
    }

    /** @return array{Company,Conversation,Message} */
    private function conversationWithInbound(string $content): array
    {
        $this->seed(WhatsAppSeeder::class);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $identifier = '5562999'.str_pad((string) (Customer::query()->count() + 1), 4, '0', STR_PAD_LEFT);
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente de teste',
            'phone' => $identifier,
            'whatsapp_id' => $identifier,
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'whatsapp_identifier' => $identifier,
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'automation_version' => 0,
            'started_at' => now(),
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => $content,
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.act-safe.'.uniqid(),
            'received_at' => now(),
        ]);

        return [$company, $conversation->fresh(), $message];
    }

    private function enableActSafe(Company $company, string $rollout = CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE): void
    {
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        AiAutomationSetting::query()->updateOrCreate(
            ['company_id' => $company->id, 'provider' => CopilotAutomationSettings::PROVIDER],
            [
                'default_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
                'automation_enabled' => true,
                'allow_auto_send' => false,
                'require_human_confirmation_for_ambiguous' => true,
                'require_human_confirmation_for_payments' => true,
                'status' => AiAutomationSetting::STATUS_ACTIVE,
                'settings' => ['rollout' => $rollout],
            ],
        );
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

    /** @return array<string,mixed> */
    private function safeReplyAnalysis(): array
    {
        return [
            'intent' => 'MENU_REQUEST',
            'suggested_reply' => 'Confira o cardápio disponível hoje.',
            'draft_order' => ['fulfillment' => null, 'items' => []],
            'missing_information' => [],
            'warnings' => [],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ];
    }

    /** @return array<string,mixed> */
    private function generalReplyAnalysis(): array
    {
        return [
            'intent' => 'GREETING',
            'confidence' => 0.99,
            'summary' => 'Saudacao simples.',
            'suggested_reply' => 'Oi! Como posso ajudar? 😊',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => null, 'payment_method' => null],
            'missing_information' => [],
            'warnings' => [],
            'requires_human_review' => true,
        ];
    }

    /** @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function semanticFixture(string $intent, array $interpretation): array
    {
        return [
            'intent' => $intent,
            'confidence' => 0.93,
            'summary' => 'Interpretação semântica do turno.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => '',
            'reply_messages' => [],
            'requires_human_review' => false,
            'interpretation' => [
                'candidate_value' => null,
                'candidate_values' => [],
                'selected_option_index' => null,
                'state_operations' => [],
                ...$interpretation,
            ],
        ];
    }

    private function availableProduct(Company $company): Product
    {
        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Bebidas',
            'slug' => 'bebidas-'.uniqid(),
        ]);

        return Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Suco Teste',
            'slug' => 'suco-teste-'.uniqid(),
            'product_type' => 'beverage',
            'base_price_cents' => 1234,
            'currency' => 'BRL',
            'is_active' => true,
            'is_available_by_default' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function readyOrderAnalysis(Product $product): array
    {
        return [
            'intent' => 'ORDER_CREATE',
            'suggested_reply' => 'Entendi: 1 Suco Teste. Confere?',
            'draft_order' => [
                'fulfillment' => 'pickup',
                'items' => [[
                    'valid' => true,
                    'menu_item_id' => $product->id,
                    'quantity' => 1,
                    'unit_price_cents' => $product->base_price_cents,
                    'validated_order_options' => [],
                    'resolved_selections' => [],
                    'removed_components' => [],
                ]],
            ],
            'missing_information' => [],
            'warnings' => [],
            'proposal' => [
                'applyability' => 'READY',
                'items' => [['valid' => true]],
                'target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false],
            ],
        ];
    }

    private function copilotReturning(array $analysis): ConversationCopilotService
    {
        $copilot = Mockery::mock(ConversationCopilotService::class);
        $copilot->shouldReceive('analyze')->once()->andReturn($analysis);

        return $copilot;
    }
}
