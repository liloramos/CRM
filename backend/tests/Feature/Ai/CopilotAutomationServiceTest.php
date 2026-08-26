<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Jobs\ProcessCopilotAutomation;
use App\Models\AiAutomationSetting;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WhatsAppMessageDelivery;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationService;
use App\Services\Ai\CopilotAutomationSettings;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationWorkflowService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
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
