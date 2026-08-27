<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\AiAutomationSetting;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationService;
use App\Services\Ai\CopilotAutomationSettings;
use App\Services\Menu\DailyStructuredMenuService;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CopilotSafeClarificationContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-26 15:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_shadow_records_a_safe_clarification_for_an_ambiguous_n8_meat_without_mutating_the_order(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $sharedToken = $this->sharedToken($options);
        $this->assertSame('frango', $sharedToken);
        $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC])->save();
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
                'settings' => ['rollout' => CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW],
            ],
        );
        $message = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $provider = new class($options[0]['name']) implements ConversationCopilotProviderInterface
        {
            public int $calls = 0;

            public function __construct(private readonly string $meat) {}

            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                $this->calls++;
                $latestMessage = (string) data_get($context, 'latest_message.body', '');
                $meat = str_contains(mb_strtolower($latestMessage), 'porco') ? 'porco' : $this->meat;

                return [
                    'intent' => 'ORDER_CREATE',
                    'draft_order' => ['items' => [[
                        'product' => 'n8',
                        'quantity' => 1,
                        'selections' => ['meats' => [$meat]],
                        'removed_components' => [],
                        'notes' => '',
                    ]]],
                    'missing_information' => [],
                    'warnings' => [],
                    'suggested_reply' => 'Resumo do pedido.',
                ];
            }
        };
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $event = app(CopilotAutomationService::class)->handle($message->id, (int) $conversation->automation_version);

        $this->assertSame('ORDER_CREATE', data_get($event?->payload, 'intent'));
        $this->assertSame('NEW_ORDER', data_get($event?->payload, 'target_state'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, data_get($event?->payload, 'rollout'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, data_get($event?->payload, 'decision'));
        $this->assertSame('send_safe_clarification', data_get($event?->payload, 'action'));
        $this->assertContains('shadow_no_execution', data_get($event?->payload, 'reason_codes', []));
        $this->assertContains('safe_clarification_available', data_get($event?->payload, 'reason_codes', []));
        $this->assertSame('requires_human_review', data_get($event?->payload, 'safe_result_status'));
        $this->assertSame(['CARNE'], data_get($event?->payload, 'guard_results.missing_information_codes'));
        $this->assertSame(['AMBIGUOUS_MEAT', 'DOMAIN_SELECTION_REJECTED'], data_get($event?->payload, 'guard_results.warning_codes'));
        $this->assertSame('ambiguous_meat', data_get($event?->payload, 'clarification_context.type'));
        $this->assertEqualsCanonicalizing(array_column($options, 'id'), data_get($event?->payload, 'clarification_context.option_component_ids'));
        $this->assertSame('not_executed', data_get($event?->response_payload, 'execution_result'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());

        $followUp = $this->inbound($conversation, 'a primeira');
        $followUpContext = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());
        $this->assertSame('eligible', data_get($followUpContext, 'pending_clarification.status'));
        $this->assertSame('resolved', data_get($followUpContext, 'pending_clarification.resolution.status'));
        $this->assertSame($options[0]['id'], data_get($followUpContext, 'pending_clarification.resolution.component_id'));
        $resolvedEvent = app(CopilotAutomationService::class)->handle($followUp->id, (int) $conversation->automation_version);

        $this->assertSame('ORDER_CONTINUE', data_get($resolvedEvent?->payload, 'intent'));
        $this->assertSame($event?->id, data_get($resolvedEvent?->payload, 'clarification_source_event_id'));
        $this->assertSame('resolved', data_get($resolvedEvent?->payload, 'clarification_resolution'));
        $this->assertSame($options[0]['id'], data_get($resolvedEvent?->payload, 'clarification_matched_option_id'));
        $this->assertNotContains('AMBIGUOUS_MEAT', data_get($resolvedEvent?->payload, 'guard_results.warning_codes', []));
        $this->assertNotContains('DOMAIN_SELECTION_REJECTED', data_get($resolvedEvent?->payload, 'guard_results.warning_codes', []));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, data_get($resolvedEvent?->payload, 'decision'));
        $this->assertSame(['order_continue_requires_human_review'], data_get($resolvedEvent?->payload, 'reason_codes'));
        $this->assertSame('not_executed', data_get($resolvedEvent?->response_payload, 'execution_result'));
        $this->assertSame(1, $provider->calls);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());

        $newOrder = $this->inbound($conversation, 'Quero uma N8 de 16 com porco.');
        $newOrderEvent = app(CopilotAutomationService::class)->handle($newOrder->id, (int) $conversation->automation_version);

        $this->assertSame('ORDER_CREATE', data_get($newOrderEvent?->payload, 'intent'));
        $this->assertSame('NEW_ORDER', data_get($newOrderEvent?->payload, 'target_state'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, data_get($newOrderEvent?->payload, 'decision'));
        $this->assertSame('stage_new_order', data_get($newOrderEvent?->payload, 'action'));
        $this->assertContains('new_order_fully_validated', data_get($newOrderEvent?->payload, 'reason_codes', []));
        $this->assertSame([], data_get($newOrderEvent?->payload, 'guard_results.warning_codes'));
        $this->assertSame([], data_get($newOrderEvent?->payload, 'guard_results.missing_information_codes'));
        $this->assertNull(data_get($newOrderEvent?->payload, 'clarification_source_event_id'));
        $this->assertNull(data_get($newOrderEvent?->payload, 'clarification_resolution'));
        $this->assertSame(2, $provider->calls);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_an_exact_reply_rehydrates_only_the_pending_product_with_a_current_menu_option(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $event = $this->pendingClarification($conversation, $source, $options);
        $reply = $this->inbound($conversation, $options[0]['name']);
        $this->providerReturnsNoItems();

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $item = $analysis['draft_order']['items'][0];
        $this->assertSame('ORDER_CONTINUE', $analysis['intent']);
        $this->assertSame('n8-tradicional', $item['menu_item_slug']);
        $this->assertSame([$options[0]['name']], $item['selections']['meats']);
        $this->assertNotContains('AMBIGUOUS_MEAT', array_column($analysis['warnings'], 'code'));
        $this->assertNotContains('DOMAIN_SELECTION_REJECTED', array_column($analysis['warnings'], 'code'));
        $this->assertSame($event->id, data_get($analysis, 'metadata.clarification_continuity.source_event_id'));
        $this->assertSame('resolved', data_get($analysis, 'metadata.clarification_continuity.resolution'));
        $this->assertSame($options[0]['id'], data_get($analysis, 'metadata.clarification_continuity.matched_option_id'));
        $this->assertSame($reply->id, Message::query()->latest('id')->value('id'));
    }

    public function test_an_ordinal_reply_uses_the_presented_option_order_without_guessing(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $this->pendingClarification($conversation, $source, $options);
        $this->inbound($conversation, 'a segunda');
        $this->providerReturnsNoItems();

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame('ORDER_CONTINUE', $analysis['intent']);
        $this->assertSame([$options[1]['name']], data_get($analysis, 'draft_order.items.0.selections.meats'));
        $this->assertNotContains('AMBIGUOUS_MEAT', array_column($analysis['warnings'], 'code'));
        $this->assertNotContains('DOMAIN_SELECTION_REJECTED', array_column($analysis['warnings'], 'code'));
        $this->assertSame('resolved', data_get($analysis, 'metadata.clarification_continuity.resolution'));
        $this->assertSame($options[1]['id'], data_get($analysis, 'metadata.clarification_continuity.matched_option_id'));
    }

    public function test_a_resolved_ordinal_does_not_recover_a_second_meat_from_the_ambiguous_source_turn(): void
    {
        CarbonImmutable::setTestNow('2026-08-27 15:00:00');
        [$company, $conversation, $options] = $this->scenario();

        $this->assertSame(['Frango ao molho', 'Filé de frango'], array_column($options, 'name'));
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $this->pendingClarification($conversation, $source, $options);
        $this->inbound($conversation, 'a segunda');
        $this->providerReturnsNoItems();

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame('ORDER_CONTINUE', $analysis['intent']);
        $this->assertSame([$options[1]['name']], data_get($analysis, 'draft_order.items.0.selections.meats'));
        $this->assertNotContains('AMBIGUOUS_MEAT', array_column($analysis['warnings'], 'code'));
        $this->assertNotContains('DOMAIN_SELECTION_REJECTED', array_column($analysis['warnings'], 'code'));
        $this->assertSame('resolved', data_get($analysis, 'metadata.clarification_continuity.resolution'));
    }

    public function test_an_invalid_reply_keeps_the_clarification_open_without_creating_a_selection(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $this->pendingClarification($conversation, $source, $options);
        $this->inbound($conversation, 'terceira');
        $this->providerReturnsNoItems();

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame('ORDER_CONTINUE', $analysis['intent']);
        $this->assertSame([], data_get($analysis, 'draft_order.items.0.selections.meats'));
        $this->assertFalse((bool) data_get($analysis, 'draft_order.items.0.valid'));
        $this->assertSame('invalid', data_get($analysis, 'metadata.clarification_continuity.resolution'));
        $this->assertSame('MEAT', data_get($analysis, 'clarification.type'));
        $this->assertCount(2, data_get($analysis, 'clarification.options'));
    }

    public function test_a_still_ambiguous_reply_does_not_guess_an_option(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $this->pendingClarification($conversation, $source, $options);
        $this->inbound($conversation, $this->sharedToken($options));
        $this->providerReturnsNoItems();

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame('ORDER_CONTINUE', $analysis['intent']);
        $this->assertSame([], data_get($analysis, 'draft_order.items.0.selections.meats'));
        $this->assertFalse((bool) data_get($analysis, 'draft_order.items.0.valid'));
        $this->assertSame('ambiguous', data_get($analysis, 'metadata.clarification_continuity.resolution'));
        $this->assertContains('AMBIGUOUS_MEAT', array_column($analysis['warnings'], 'code'));
        $this->assertSame('MEAT', data_get($analysis, 'clarification.type'));
    }

    public function test_a_new_independent_ambiguity_remains_fail_closed_after_a_resolved_clarification(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $clarification = $this->pendingClarification($conversation, $source, $options);
        $resolvedReply = $this->inbound($conversation, 'a primeira');
        AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $resolvedReply->id,
            'provider' => 'copilot',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_SKIPPED,
            'payload' => [
                'clarification_source_event_id' => $clarification->id,
                'clarification_resolution' => 'resolved',
                'clarification_matched_option_id' => $options[0]['id'],
            ],
            'response_payload' => ['execution_result' => 'not_executed'],
            'processed_at' => now(),
        ]);
        $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $this->providerReturnsNoItems([
            ['code' => 'AMBIGUOUS_MEAT', 'message' => 'A nova escolha de carne permanece ambigua.'],
        ]);

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame('ORDER_CREATE', $analysis['intent']);
        $this->assertNull(data_get($analysis, 'metadata.clarification_continuity.source_event_id'));
        $this->assertContains('AMBIGUOUS_MEAT', array_column($analysis['warnings'], 'code'));
        $this->assertTrue((bool) $analysis['requires_human_review']);
    }

    public function test_a_new_explicit_order_supersedes_the_pending_clarification(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $this->pendingClarification($conversation, $source, $options);
        $this->inbound($conversation, 'Quero uma N8 de 16 com porco.');
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                return ['intent' => 'ORDER_CREATE', 'draft_order' => ['items' => [['product' => 'n8', 'quantity' => 1, 'selections' => ['meats' => ['porco']]]]], 'missing_information' => [], 'warnings' => [], 'suggested_reply' => 'Resumo do pedido.'];
            }
        });

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());

        $this->assertSame('ORDER_CREATE', $analysis['intent']);
        $this->assertSame(['Porco'], data_get($analysis, 'draft_order.items.0.selections.meats'));
        $this->assertSame('superseded', data_get($analysis, 'metadata.clarification_continuity.resolution'));
    }

    public function test_an_operational_day_boundary_makes_an_old_clarification_stale(): void
    {
        CarbonImmutable::setTestNow('2026-08-27 10:00:00');
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.', '2026-08-26 18:00:00');
        $this->pendingClarification($conversation, $source, $options);
        $this->inbound($conversation, $options[0]['name'], '2026-08-27 10:00:00');

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

        $this->assertSame('stale', data_get($context, 'pending_clarification.status'));
        $this->assertSame('cycle_boundary', data_get($context, 'pending_clarification.resolution.reason'));
    }

    public function test_a_removed_menu_option_makes_the_snapshot_stale_before_the_reply_is_used(): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $event = $this->pendingClarification($conversation, $source, $options);
        $event->forceFill(['payload' => [...(array) $event->payload, 'clarification_context' => [
            ...data_get($event->payload, 'clarification_context'),
            'option_component_ids' => [$options[0]['id'], 999999],
        ]]])->save();
        $this->inbound($conversation, $options[0]['name']);

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

        $this->assertSame('stale', data_get($context, 'pending_clarification.status'));
        $this->assertSame('menu_changed', data_get($context, 'pending_clarification.resolution.reason'));
    }

    #[DataProvider('terminalClarificationResolutions')]
    public function test_a_terminal_clarification_event_prevents_an_old_clarification_from_reappearing(string $resolution): void
    {
        [$company, $conversation, $options] = $this->scenario();
        $source = $this->inbound($conversation, 'Quero uma N8 de 16 com frango.');
        $clarification = $this->pendingClarification($conversation, $source, $options);
        $supersedingMessage = $this->inbound($conversation, 'Quero uma N8 de 16 com porco.');
        AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $supersedingMessage->id,
            'provider' => 'copilot',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_SKIPPED,
            'payload' => ['clarification_source_event_id' => $clarification->id, 'clarification_resolution' => $resolution],
            'response_payload' => ['execution_result' => 'not_executed'],
            'processed_at' => now(),
        ]);
        $this->inbound($conversation, 'a primeira');

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

        $this->assertNull(data_get($context, 'pending_clarification'));
    }

    /** @return array<string, array{string}> */
    public static function terminalClarificationResolutions(): array
    {
        return [
            'resolved' => ['resolved'],
            'invalid' => ['invalid'],
            'rejected' => ['rejected'],
            'stale' => ['stale'],
            'superseded' => ['superseded'],
        ];
    }

    /** @return array{Company,Conversation,list<array{id:int,name:string}>} */
    private function scenario(): array
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de teste']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        $meats = collect(data_get(app(DailyStructuredMenuService::class)->day($company, CarbonImmutable::now()), 'sections.meat', []))
            ->filter(fn (array $entry): bool => (bool) ($entry['available'] ?? false))
            ->map(fn (array $entry): array => ['id' => (int) data_get($entry, 'component.id'), 'name' => (string) (data_get($entry, 'component.display_name') ?: data_get($entry, 'component.name'))])
            ->filter(fn (array $option): bool => $option['id'] > 0 && $option['name'] !== '')
            ->values()
            ->all();
        $options = collect($meats)->crossJoin($meats)
            ->filter(fn (array $pair): bool => $pair[0]['id'] < $pair[1]['id'] && $this->sharedToken([$pair[0], $pair[1]]) !== '')
            ->map(fn (array $pair): array => $pair)
            ->first();

        $this->assertIsArray($options);
        $this->assertCount(2, $options);

        return [$company, $conversation, $options];
    }

    /** @param list<array{id:int,name:string}> $options */
    private function pendingClarification(Conversation $conversation, Message $source, array $options): AutomationEvent
    {
        $product = Product::query()->where('company_id', $conversation->company_id)->where('slug', 'n8-tradicional')->firstOrFail();

        return AutomationEvent::query()->create([
            'company_id' => $conversation->company_id,
            'conversation_id' => $conversation->id,
            'message_id' => $source->id,
            'provider' => 'copilot',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_SKIPPED,
            'payload' => [
                'action' => 'send_safe_clarification',
                'clarification_context' => [
                    'type' => 'ambiguous_meat',
                    'selection_group' => 'meat',
                    'source_message_id' => $source->id,
                    'active_order_id' => null,
                    'candidate' => ['product_id' => $product->id, 'product_slug' => $product->slug, 'quantity' => 1],
                    'option_component_ids' => array_column($options, 'id'),
                ],
            ],
            'response_payload' => ['execution_result' => 'not_executed'],
            'processed_at' => now(),
        ]);
    }

    private function inbound(Conversation $conversation, string $content, ?string $at = null): Message
    {
        $timestamp = $at === null ? now() : CarbonImmutable::parse($at, 'America/Sao_Paulo');
        $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $content, 'type' => 'text', 'received_at' => $timestamp]);
        if ($at !== null) {
            $message->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();
        }

        return $message;
    }

    /** @param list<array{code:string,message:string}> $warnings */
    private function providerReturnsNoItems(array $warnings = []): void
    {
        $this->app->instance(ConversationCopilotProviderInterface::class, new class($warnings) implements ConversationCopilotProviderInterface
        {
            /** @param list<array{code:string,message:string}> $warnings */
            public function __construct(private readonly array $warnings) {}

            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                return ['intent' => 'ORDER_CONTINUE', 'draft_order' => ['items' => []], 'missing_information' => [], 'warnings' => $this->warnings, 'suggested_reply' => 'Resumo do pedido.'];
            }
        });
    }

    /** @param list<array{id:int,name:string}> $options */
    private function sharedToken(array $options): string
    {
        if (count($options) !== 2) {
            return '';
        }

        $first = preg_split('/[^a-z0-9]+/', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $options[0]['name']) ?: $options[0]['name'])) ?: [];
        $second = preg_split('/[^a-z0-9]+/', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $options[1]['name']) ?: $options[1]['name'])) ?: [];

        return collect($first)
            ->filter(fn (string $token): bool => strlen($token) >= 4 && in_array($token, $second, true))
            ->first() ?? '';
    }
}
