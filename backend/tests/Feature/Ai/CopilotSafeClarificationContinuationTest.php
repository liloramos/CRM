<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Product;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Menu\DailyStructuredMenuService;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('resolved', data_get($analysis, 'metadata.clarification_continuity.resolution'));
        $this->assertSame($options[1]['id'], data_get($analysis, 'metadata.clarification_continuity.matched_option_id'));
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
        $this->assertSame('MEAT', data_get($analysis, 'clarification.type'));
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

    public function test_a_terminal_supersession_event_prevents_an_old_clarification_from_reappearing(): void
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
            'payload' => ['clarification_source_event_id' => $clarification->id, 'clarification_resolution' => 'superseded'],
            'response_payload' => ['execution_result' => 'not_executed'],
            'processed_at' => now(),
        ]);
        $this->inbound($conversation, 'a primeira');

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

        $this->assertSame('superseded', data_get($context, 'pending_clarification.status'));
        $this->assertSame('superseded', data_get($context, 'pending_clarification.resolution.status'));
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

    private function providerReturnsNoItems(): void
    {
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'test';
            }

            public function analyze(array $context): array
            {
                return ['intent' => 'ORDER_CONTINUE', 'draft_order' => ['items' => []], 'missing_information' => [], 'warnings' => [], 'suggested_reply' => 'Resumo do pedido.'];
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
