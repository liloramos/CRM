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
use App\Models\DeliverySetting;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WhatsAppMessageDelivery;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationService;
use App\Services\Ai\CopilotAutomationSettings;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Conversations\ConversationWorkflowService;
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
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
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
            'content' => 'Porco',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.n5-meat.'.uniqid(),
            'received_at' => now(),
        ]);

        $resolved = app(CopilotAutomationService::class)->handle($reply->id, (int) $conversation->automation_version);
        $retried = app(CopilotAutomationService::class)->handle($reply->id, (int) $conversation->automation_version);
        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
            'intent' => 'ORDER_CONTINUE',
            'confidence' => 0.99,
            'draft_order' => ['items' => [[
                'menu_item_id' => $n5->id,
                'menu_item_slug' => $n5->slug,
                'quantity' => 1,
                'selections' => ['meat' => 'porco'],
            ]], 'fulfillment' => 'pickup'],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => 'Certo, vou organizar para retirada.',
        ]));
        $pickupReply = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'retirada',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.n5-pickup.'.uniqid(),
            'received_at' => now(),
        ]);
        $staged = app(CopilotAutomationService::class)->handle($pickupReply->id, (int) $conversation->automation_version);
        $stagedRetry = app(CopilotAutomationService::class)->handle($pickupReply->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($clarification?->payload, 'decision'));
        $this->assertTrue((bool) data_get($clarification?->payload, 'waiting_for_customer'));
        $this->assertSame('LOCATION_REQUEST', data_get($location?->payload, 'intent'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($location?->payload, 'decision'));
        $this->assertStringContainsString('Rua Configurada, 123', Message::query()->where('reply_to_message_id', $locationQuestion->id)->firstOrFail()->content);
        $this->assertSame('ORDER_CONTINUE', data_get($resolved?->payload, 'intent'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($resolved?->payload, 'decision'), json_encode($resolved?->payload, JSON_PRETTY_PRINT));
        $this->assertSame('send_grounded_reply', data_get($resolved?->payload, 'action'));
        $this->assertSame(['fulfillment_required'], data_get($resolved?->payload, 'reason_codes'));
        $this->assertSame('resolved', data_get($resolved?->payload, 'clarification_resolution'));
        $this->assertSame([], data_get($resolved?->payload, 'guard_results.missing_information_codes'));
        $this->assertSame([], data_get($resolved?->payload, 'guard_results.warning_codes'));
        $this->assertTrue((bool) data_get($resolved?->payload, 'waiting_for_customer'));
        $this->assertSame($resolved?->id, $retried?->id);
        $resolvedReply = Message::query()->where('direction', 'outbound')->where('reply_to_message_id', $reply->id)->firstOrFail();
        $this->assertStringContainsString('Porco', $resolvedReply->content);
        $this->assertStringContainsString('retirada ou entrega', $resolvedReply->content);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION, data_get($staged?->payload, 'decision'), json_encode($staged?->payload, JSON_PRETTY_PRINT));
        $this->assertSame('stage_new_order', data_get($staged?->payload, 'action'));
        $this->assertSame($staged?->id, $stagedRetry?->id);
        $this->assertSame(4, Message::query()->where('direction', 'outbound')->count());
        $this->assertFalse($conversation->fresh()->human_review_required);
        $this->assertSame(ConversationAlert::STATUS_RESOLVED, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->firstOrFail()->status);
        $this->assertSame(1, Order::count());
        $this->assertSame(Order::FULFILLMENT_PICKUP, Order::query()->firstOrFail()->fulfillment_type);
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

    public function test_equivalent_human_review_alerts_are_deduplicated_with_operational_copy(): void
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

        $alert = ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->firstOrFail();
        $this->assertSame(1, ConversationAlert::query()->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)->count());
        $this->assertSame($secondMessage->id, $alert->message_id);
        $this->assertSame('Revisão necessária — Cliente de teste', $alert->title);
        $this->assertSame('N5 Casa precisa de confirmação: falta escolher a carne.', $alert->message);
        $this->assertStringNotContainsString('no_safe_action_candidate', $alert->message);
        $this->assertSame(['CARNE'], data_get($alert->metadata, 'missing_information_codes'));
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

    public function test_act_safe_sends_a_deduplicated_grounded_menu_reply_with_the_fake_provider(): void
    {
        [$company, $conversation, $message] = $this->conversationWithInbound('qual é o cardápio?');
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
        $this->enableActSafe($company);

        $first = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);
        $second = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($first?->payload, 'decision'));
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(AutomationEvent::STATUS_DISPATCHED, $first?->fresh()->status);
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

    public function test_act_safe_stages_only_a_fully_validated_new_order_using_backend_price(): void
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
        $order = Order::query()->firstOrFail();

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION, data_get($event?->payload, 'decision'));
        $this->assertSame($order->id, $event?->order_id);
        $this->assertSame($conversation->id, $order->conversation_id);
        $this->assertSame(Order::CHANNEL_WHATSAPP, $order->origin_channel);
        $this->assertTrue($order->human_review_required);
        $this->assertSame(1234, $order->items()->firstOrFail()->unit_price_cents);
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
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
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
        $whatsapp->shouldReceive('sendTextMessage')->once()->withArgs(function (Company $sentCompany, string $to, string $body, array $attributes) use ($company, $message): bool {
            return $sentCompany->is($company)
                && $to !== ''
                && $body !== ''
                && $attributes['client_reference'] === 'copilot-act-safe:v1:inbound:'.$message->id;
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
