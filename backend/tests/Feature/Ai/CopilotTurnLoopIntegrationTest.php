<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Jobs\ProcessCopilotAutomation;
use App\Jobs\ProcessWhatsAppWebhookEvent;
use App\Models\AiAutomationSetting;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\DeliveryQuote;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationService;
use App\Services\Ai\CopilotAutomationSettings;
use App\Services\Ai\CopilotOrderClarificationReplyBuilder;
use App\Services\Ai\CopilotTurnStateReducer;
use App\Services\Delivery\DeliveryRoutingService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CopilotTurnLoopIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_reducer_never_confirms_an_invalid_or_required_incomplete_item(): void
    {
        $invalid = app(CopilotTurnStateReducer::class)->reduce([
            'intent' => 'ORDER_CONTINUE',
            'draft_order' => [
                'items' => [[
                    'menu_item_id' => 88,
                    'menu_item_slug' => 'n8-tradicional',
                    'quantity' => 1,
                    'selections' => ['meats' => []],
                    'daily_component_ids' => [],
                    'valid' => false,
                ]],
                'fulfillment' => null,
                'address' => '',
                'payment_method' => '',
            ],
            'missing_information' => [],
            'warnings' => [['code' => 'PROVIDER_UNAVAILABLE']],
            'metadata' => ['provider_failure' => true],
        ], ['daily_meats' => [['id' => 1, 'slug' => 'porco', 'name' => 'Porco']]]);

        $this->assertSame('BUILD_ITEM', $invalid['phase']);
        $this->assertSame('ASK_MEAT', $invalid['next_objective']);
        $this->assertContains('CARNE', $invalid['required_missing_slots']);
        $this->assertSame('pending', data_get($invalid, 'item_confirmation.status'));

        $confirmedWithMissing = app(CopilotTurnStateReducer::class)->reduce([
            'intent' => 'ORDER_CONFIRMATION',
            'draft_order' => ['items' => [[
                'menu_item_id' => 88,
                'menu_item_slug' => 'n8-tradicional',
                'quantity' => 1,
                'selections' => ['meats' => []],
                'valid' => false,
            ]]],
            'missing_information' => [['code' => 'CARNE']],
            'warnings' => [],
            'metadata' => ['semantic_interpretation' => ['intents' => ['confirm'], 'confirmation' => 'yes']],
        ], [
            'pending_order_state' => [
                'next_objective' => 'CONFIRM_ITEM',
                'draft_order' => ['items' => [['menu_item_id' => 88, 'menu_item_slug' => 'n8-tradicional', 'valid' => false]]],
            ],
        ]);

        $this->assertSame('ASK_MEAT', $confirmedWithMissing['next_objective']);
        $this->assertSame('pending', data_get($confirmedWithMissing, 'item_confirmation.status'));
        $this->assertNotContains('PRODUCT', $confirmedWithMissing['required_missing_slots']);

        $delivery = app(CopilotTurnStateReducer::class)->reduce([
            'intent' => 'ORDER_CONTINUE',
            'draft_order' => [
                'items' => [['menu_item_id' => 88, 'menu_item_slug' => 'n8-tradicional', 'valid' => true]],
                'fulfillment' => 'delivery',
                'address' => '',
                'payment_method' => '',
            ],
            'missing_information' => [],
            'warnings' => [],
            'metadata' => [],
        ], [
            'pending_order_state' => [
                'next_objective' => 'ASK_FULFILLMENT',
                'item_confirmation' => ['status' => 'confirmed'],
                'additional_items' => ['status' => 'declined'],
                'draft_order' => [
                    'items' => [['menu_item_id' => 88, 'menu_item_slug' => 'n8-tradicional', 'valid' => true]],
                    'fulfillment' => null,
                    'address' => '',
                    'payment_method' => '',
                ],
            ],
        ]);
        $this->assertSame('ASK_LOCATION', $delivery['next_objective']);
        $this->assertFalse((bool) data_get($delivery, 'review.required'));
    }

    public function test_late_webhook_timestamp_keeps_the_job_inbound_as_the_turn_envelope_trigger(): void
    {
        CarbonImmutable::setTestNow('2026-09-08 12:00:00');
        $this->seed([WhatsAppSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $this->enableActSafe($company);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente tardio'])->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
            'started_at' => now(),
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'assistant',
            'direction' => 'outbound',
            'content' => 'resposta anterior',
            'type' => 'text',
            'sent_at' => now(),
        ]);
        $trigger = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'sender_type' => 'customer',
            'direction' => 'inbound',
            'content' => 'oi',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.late.turn-loop',
            'received_at' => now()->subMinute(),
        ]);

        $event = app(CopilotAutomationService::class)->handle($trigger->id, (int) $conversation->automation_version);

        $this->assertSame($trigger->id, data_get($event?->payload, 'turn_envelope.trigger_inbound_message_id'));
        $this->assertSame('wamid.late.turn-loop', data_get($event?->payload, 'turn_envelope.trigger_external_message_id'));
        $this->assertSame('conversation:'.$conversation->id.':inbound:'.$trigger->id, data_get($event?->payload, 'turn_id'));
        $this->assertSame([$trigger->id], data_get($event?->payload, 'turn_trace.trigger_message_record_ids'));
    }

    public function test_real_fourteen_turn_smoke_keeps_one_canonical_state_and_one_final_composer(): void
    {
        CarbonImmutable::setTestNow('2026-09-08 12:00:00');
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $n8 = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $context = app(ConversationCopilotContextBuilder::class)->forMessages($company, [[
            'direction' => 'inbound', 'type' => 'text', 'body' => 'quero o cardapio',
        ]], null, now());
        $candidate = [[
            'token' => 'repolho',
            'component_ids' => [13, 26, 14],
            'names' => ['Repolho alho e óleo', 'Repolho com tomate', 'Repolho com maionese'],
            'status' => 'candidate',
        ]];
        $invalidDraft = ['items' => [[
            'menu_item_id' => $n8->id, 'menu_item_slug' => $n8->slug, 'quantity' => 1,
            'selections' => ['meats' => []], 'daily_component_ids' => [], 'valid' => false,
        ]], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        $candidateDraft = ['items' => [[
            'menu_item_id' => $n8->id, 'menu_item_slug' => $n8->slug, 'quantity' => 1,
            'selections' => ['meats' => ['Almôndega', 'Porco']], 'daily_component_ids' => [5, 7, 9],
            'daily_component_candidates' => $candidate, 'valid' => true,
        ]], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        $resolvedDraft = $candidateDraft;
        data_set($resolvedDraft, 'items.0.daily_component_ids', [5, 7, 9, 26]);
        data_set($resolvedDraft, 'items.0.daily_component_candidates', []);
        $turns = [
            ['quero o cardapio', 'MENU_REQUEST', [], [], true, 'Cardápio de hoje.'],
            ['lista de componentes antes de produto', 'ORDER_CREATE', [], ['PRODUCT'], false, ''],
            ['n8 livre', 'UNKNOWN', $invalidDraft, [], false, '', ['provider_failure' => true]],
            ['fazer pedido', 'ORDER_CONTINUE', $invalidDraft, ['CARNE'], false, ''],
            ['n8 livre', 'ORDER_CONTINUE', $invalidDraft, ['CARNE'], false, ''],
            ['componentes + carnes', 'ORDER_CONTINUE', $candidateDraft, ['ACOMPANHAMENTO'], false, ''],
            ['isso', 'ORDER_CONFIRMATION', $candidateDraft, ['ACOMPANHAMENTO'], false, '', ['semantic_interpretation' => ['intents' => ['confirm'], 'confirmation' => 'yes']]],
            ['qual é o pix?', 'PAYMENT_QUESTION', [], [], true, 'A chave Pix é pix-smoke-real.'],
            ['nenhum, apenas a marmitex', 'ORDER_CONTINUE', $candidateDraft, ['ACOMPANHAMENTO'], false, ''],
            ['vai ser entrega', 'ORDER_CONTINUE', [...$candidateDraft, 'fulfillment' => 'delivery'], ['ACOMPANHAMENTO'], false, ''],
            ['repolho com tomate', 'ORDER_CONTINUE', $resolvedDraft, [], false, ''],
            ['já escolhi', 'ORDER_CONFIRMATION', $resolvedDraft, [], false, '', ['semantic_interpretation' => ['intents' => ['confirm'], 'confirmation' => 'yes']]],
            ['é entrega', 'ORDER_CONTINUE', [...$resolvedDraft, 'fulfillment' => 'delivery'], [], false, ''],
            ['qual valor da entrega?', 'DELIVERY_QUESTION', [], [], true, 'A taxa depende do endereço.'],
        ];
        $states = [];
        foreach ($turns as $index => $turn) {
            [$text, $intent, $draft, $missing, $readOnly, $information, $extraMetadata] = array_pad($turn, 7, []);
            $analysis = [
                'intent' => $intent,
                'draft_order' => $draft ?: ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                'missing_information' => array_map(fn (string $code): array => ['code' => $code], $missing),
                'warnings' => [],
                'suggested_reply' => $information,
                'reply_messages' => $information === '' ? [] : [$information],
                'metadata' => [...($extraMetadata ?? []), ...($readOnly ? ['semantic_read_only' => true] : [])],
            ];
            $turnContext = [...$context, 'latest_message' => ['body' => $text], 'trigger_message' => ['id' => $index + 1, 'body' => $text], 'pending_order_state' => $states[$index - 1] ?? null];
            $state = app(CopilotTurnStateReducer::class)->reduce($analysis, $turnContext);
            $composed = app(CopilotOrderClarificationReplyBuilder::class)->continueFromObjective($analysis, $state, $turnContext);
            $states[] = $state;

            $this->assertSame('post_reducer_turn_loop', data_get($composed, 'metadata.final_reply_composer'));
            $this->assertLessThanOrEqual(2, count((array) ($composed['reply_messages'] ?? [])));
            $items = (array) data_get($state, 'draft_order.items', []);
            $required = (array) data_get($state, 'required_missing_slots', []);
            if ($items !== []) {
                $this->assertNotContains('PRODUCT', $required);
            }
            if ($state['next_objective'] === 'CONFIRM_ITEM') {
                $this->assertSame([], $required);
                $this->assertTrue(collect($items)->every(fn (array $item): bool => ($item['valid'] ?? false) === true));
            }
        }
        $candidateIds = collect((array) data_get($states[5], 'draft_order.items.0.daily_component_candidates', []))
            ->flatMap(fn (array $candidate): array => (array) ($candidate['component_ids'] ?? []))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $selectedIds = array_map('intval', (array) data_get($states[5], 'draft_order.items.0.daily_component_ids', []));
        $this->assertSame([], array_values(array_intersect($candidateIds, $selectedIds)));
        $this->assertNotSame('CONFIRM_ITEM', data_get($states[2], 'next_objective'));
        $meatLabels = collect((array) data_get($states[4], 'assistant_goal.allowed_values'))->pluck('label')->all();
        $this->assertNotContains('N8 Livre', $meatLabels);
        $this->assertContains('Porco', $meatLabels);
        $this->assertSame('ASK_COMPONENTS', data_get($states[6], 'next_objective'));
        $this->assertSame(data_get($states[6], 'draft_order'), data_get($states[7], 'draft_order'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_meta_turn_loop_preserves_state_answers_read_only_questions_and_resolves_constraints(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');
        $this->seed([WhatsAppSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        Queue::fake();
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $this->enableActSafe($company);
        $n8Livre = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();

        $discovery = $this->turn($company, 1, 'quero uma marmita', fn (): array => $this->fixture('ORDER_CREATE', [
            'intents' => ['generic_order'], 'subject' => 'product', 'mutates_order' => false,
            'facts_needed' => ['product_details'], 'reply_goal' => 'discover_product',
        ]));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($discovery->payload, 'decision'));
        $this->assertNotEmpty(data_get($discovery->payload, 'resolved_turn.canonical_facts.product_ids'));
        $this->assertTrue((bool) data_get($discovery->payload, 'turn_trace.provider_interpretation_called'));
        $this->assertSame('ask_product', data_get($discovery->payload, 'turn_trace.next_action'));

        $selection = $this->turn($company, 2, 'N8 Livre', fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['select_option'], 'subject' => 'product', 'candidate_value' => 'N8 Livre',
            'target_products' => ['n8-tradicional'], 'mutates_order' => true,
            'facts_needed' => ['product_details'], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->assertSame('n8-tradicional', data_get($selection->payload, 'order_context.draft_order.items.0.menu_item_slug'), json_encode($selection->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');

        $components = $this->turn($company, 3, 'arroz branco feijão macarrão vermelho alface e tomate', fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['order_request'], 'subject' => 'order', 'mutates_order' => true,
            'facts_needed' => ['daily_menu'], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $stateWithComponents = (array) data_get($components->payload, 'order_context.draft_order');
        $this->assertContains('CARNE', data_get($components->payload, 'order_context.missing_fields', []));
        $this->assertGreaterThanOrEqual(3, count((array) data_get($stateWithComponents, 'items.0.daily_component_ids')));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($components->payload, 'decision'));
        $this->assertFalse((bool) $components->requires_human_confirmation);

        $meatQuestion = $this->turn($company, 4, 'e quais carnes tem?', fn (): array => $this->fixture('GENERAL_QUESTION', [
            'intents' => ['ask_pending_slot_options'], 'subject' => 'carne', 'mutates_order' => false,
            'facts_needed' => ['pending_slot_options', 'daily_meats'], 'reply_goal' => 'answer_and_continue',
        ]));
        $this->assertSame($stateWithComponents, data_get($meatQuestion->payload, 'turn_trace.state_after'));
        $this->assertSame($stateWithComponents, data_get($meatQuestion->payload, 'order_context.draft_order'));
        $this->assertSame('BUILD_ITEM', data_get($meatQuestion->payload, 'order_context.phase'));
        $this->assertSame('ASK_MEAT', data_get($meatQuestion->payload, 'order_context.next_objective'));
        $this->assertSame([], data_get($meatQuestion->payload, 'turn_trace.state_delta'));
        $this->assertLessThanOrEqual(2, count((array) data_get($meatQuestion->payload, 'reply_messages')));
        $this->assertSame('post_reducer_turn_loop', data_get($meatQuestion->payload, 'turn_envelope.reply_composer'));
        $this->assertStringNotContainsStringIgnoringCase('como posso te ajudar', implode(' ', data_get($meatQuestion->payload, 'reply_messages', [])));

        foreach ([
            [5, 'quanto fica?', 'ORDER_STATUS', ['ask_product_information'], 'total'],
            [6, 'tem coca?', 'PRODUCT_CLARIFICATION', ['ask_product_information'], 'beverage'],
            [7, 'qual a diferença?', 'GENERAL_QUESTION', ['compare_products'], 'product'],
        ] as [$sequence, $question, $intent, $intents, $subject]) {
            $interruption = $this->turn($company, $sequence, $question, fn (): array => $this->fixture($intent, [
                'intents' => $intents, 'subject' => $subject, 'mutates_order' => false,
                'facts_needed' => ['product_details'], 'reply_goal' => 'answer_and_continue',
            ]));
            $this->assertSame($stateWithComponents, data_get($interruption->payload, 'order_context.draft_order'));
            $this->assertTurnState($interruption, 'BUILD_ITEM', 'ASK_MEAT');
        }

        $constraint = $this->turn($company, 8, 'Pode ser almôndega churrasco e porco', function (array $context): array {
            $draft = (array) data_get($context, 'pending_order_state.draft_order');
            data_set($draft, 'items.0.selections.meats', ['Almôndega', 'Churrasco', 'Porco']);

            return $this->fixture('ORDER_CONTINUE', [
                'intents' => ['answer_pending_slot'], 'subject' => 'carne',
                'candidate_values' => ['Almôndega', 'Churrasco', 'Porco'], 'mutates_order' => true,
                'facts_needed' => ['daily_meats', 'product_details'], 'reply_goal' => 'apply_commercial_constraint',
            ], $draft);
        });
        $this->assertSame('MEAT_ALLOWANCE_EXCEEDED', data_get($constraint->payload, 'resolved_turn.constraints.0.code'));
        $this->assertSame('additional_applied', data_get($constraint->payload, 'resolved_turn.constraints.0.resolution'));
        $this->assertSame('explain_constraint', data_get($constraint->payload, 'turn_trace.next_action'));
        $this->assertFalse((bool) data_get($constraint->payload, 'turn_trace.provider_naturalizer_called'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($constraint->payload, 'decision'));
        $this->assertFalse((bool) $constraint->requires_human_confirmation);

        $corrected = $this->turn($company, 9, 'tira churrasco fica almôndega e porco', function (array $context): array {
            $draft = (array) data_get($context, 'pending_order_state.draft_order');
            data_set($draft, 'items.0.selections.meats', ['Almôndega', 'Porco']);

            return $this->fixture('ORDER_CHANGE', [
                'intents' => ['correct_order'], 'subject' => 'carne', 'mutates_order' => true,
                'facts_needed' => ['active_order'], 'reply_goal' => 'continue_order',
                'state_operations' => [[
                    'operation' => 'remove', 'subject' => 'carne', 'from' => 'Churrasco', 'to' => null,
                ]],
            ], $draft);
        });
        $this->assertSame(
            ['Almôndega', 'Porco'],
            data_get($corrected->payload, 'order_context.draft_order.items.0.selections.meats'),
            json_encode($corrected->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '',
        );
        $this->assertSame([], data_get($corrected->payload, 'resolved_turn.constraints'));
        $this->assertSame('CONFIRM_ITEM', data_get($corrected->payload, 'order_context.phase'));
        $this->assertSame('CONFIRM_ITEM', data_get($corrected->payload, 'order_context.next_objective'));
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, data_get($corrected->payload, 'decision'));

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame(0, ConversationAlert::query()->where('conversation_id', $conversation->id)->currentActionable()->count());
        $this->assertFalse($conversation->human_review_required);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertGreaterThanOrEqual(9, Message::query()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertLessThanOrEqual(27, Message::query()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
    }

    public function test_meta_like_n8_candidate_overlap_and_multi_operation_correction_keep_one_state(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 12:00:00');
        $this->seed([WhatsAppSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        Queue::fake();
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $this->enableActSafe($company);

        $first = $this->turn($company, 71, 'N8 com arroz, feijão, macarrão, almôndega e bisteca de porco na chapa', fn (): array => $this->fixture('ORDER_CREATE', [
            'intents' => ['order_request'], 'subject' => 'order', 'mutates_order' => true,
            'facts_needed' => ['daily_menu', 'daily_meats', 'product_details'], 'reply_goal' => 'continue_order',
        ]));
        $firstDiagnostic = json_encode([
            'intent' => data_get($first->payload, 'intent'),
            'action' => data_get($first->payload, 'action'),
            'clarification_kind' => data_get($first->payload, 'clarification_kind'),
            'candidate_items' => data_get($first->payload, 'order_context.candidate_items'),
            'resolved_candidate' => data_get($first->payload, 'order_context.resolved_candidate'),
            'draft_items' => data_get($first->payload, 'order_context.draft_order.items'),
            'warnings' => data_get($first->payload, 'order_context.validation_warnings'),
            'next_objective' => data_get($first->payload, 'order_context.next_objective'),
            'reply_messages' => data_get($first->payload, 'reply_messages'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
        $firstCandidate = data_get($first->payload, 'order_context.candidate_items.0')
            ?? data_get($first->payload, 'order_context.resolved_candidate');
        $this->assertSame('n8', data_get($firstCandidate, 'family'), $firstDiagnostic);
        $this->assertContains(data_get($firstCandidate, 'status'), ['candidate', 'resolved']);
        $firstMeats = collect((array) data_get($firstCandidate, 'meats'));
        $this->assertCount(2, $firstMeats, json_encode($first->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertTrue($firstMeats->contains(fn (string $meat): bool => str_contains(mb_strtolower($meat), 'bisteca')));
        $this->assertFalse($firstMeats->contains(fn (string $meat): bool => mb_strtolower($meat) === 'porco'));
        $this->assertSame(1, count((array) data_get($first->payload, 'reply_messages')));

        $second = $this->turn($company, 72, 'N8 Livre', fn (): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['select_option'], 'subject' => 'product', 'candidate_value' => 'N8 Livre',
            'mutates_order' => true, 'facts_needed' => ['product_details'], 'reply_goal' => 'continue_order',
        ]));
        $this->assertSame('n8-tradicional', data_get($second->payload, 'order_context.draft_order.items.0.menu_item_slug'));
        $this->assertSame($firstMeats->values()->all(), data_get($second->payload, 'order_context.draft_order.items.0.selections.meats'));
        $this->assertSame(data_get($first->payload, 'order_context.session_id'), data_get($second->payload, 'order_context.session_id'));
        $secondReply = implode("\n", (array) data_get($second->payload, 'reply_messages'));
        $this->assertStringNotContainsString('N5 Casa', $secondReply);
        $this->assertStringNotContainsString('N9 Livre', $secondReply);

        $third = $this->turn($company, 73, 'acrescenta porco', fn (): array => $this->fixture('ORDER_CHANGE', [
            'intents' => ['correct_order'], 'subject' => 'carne', 'mutates_order' => true,
            'state_operations' => [[
                'operation' => 'add', 'subject' => 'carne', 'from' => null, 'to' => 'Porco', 'confidence' => 0.98,
            ]],
            'facts_needed' => ['daily_meats'], 'reply_goal' => 'apply_commercial_constraint',
        ]));
        $this->assertCount(3, data_get($third->payload, 'order_context.draft_order.items.0.selections.meats', []));

        $corrected = $this->turn($company, 74, 'retira o porco, quero só a bisteca e a almôndega', fn (): array => $this->fixture('ORDER_CHANGE', [
            'intents' => ['correct_order', 'answer_pending_slot', 'select_option'], 'subject' => 'carne',
            'candidate_values' => ['Bisteca de porco na chapa', 'Almôndega'], 'mutates_order' => true,
            'state_operations' => [[
                'operation' => 'remove', 'subject' => 'carne', 'from' => 'Porco', 'to' => null, 'confidence' => 0.99,
            ]],
            'facts_needed' => ['daily_meats'], 'reply_goal' => 'continue_order',
        ]));
        $correctedMeats = collect((array) data_get($corrected->payload, 'order_context.draft_order.items.0.selections.meats'));
        $this->assertCount(2, $correctedMeats, json_encode($corrected->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertFalse($correctedMeats->contains(fn (string $meat): bool => mb_strtolower($meat) === 'porco'));
        $this->assertTrue((bool) data_get($corrected->payload, 'turn_trace.canonical_resolution.validated'));
        $this->assertSame(
            0,
            ConversationAlert::query()
                ->where('conversation_id', $corrected->conversation_id)
                ->where('type', 'low_confidence_ai')
                ->currentActionable()
                ->count(),
            json_encode([
                'decision' => data_get($corrected->payload, 'decision'),
                'reason_codes' => data_get($corrected->payload, 'reason_codes'),
                'warnings' => data_get($corrected->payload, 'warnings'),
                'missing' => data_get($corrected->payload, 'missing_information'),
                'review' => data_get($corrected->payload, 'order_context.review'),
                'alerts' => ConversationAlert::query()->where('conversation_id', $corrected->conversation_id)->get([
                    'id',
                    'type',
                    'status',
                    'message_id',
                    'deduplication_key',
                    'metadata',
                ])->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '',
        );
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_canonical_ten_turn_sale_reaches_pix_proof_wait_without_payment_confirmation(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');
        $this->seed([WhatsAppSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        Queue::fake();
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $company->setting()->updateOrCreate([], ['settings' => [
            'payments' => ['pix' => ['public_key' => 'pix-turn-loop'], 'methods' => ['pix' => true]],
        ]]);
        $company->unsetRelation('setting');
        $this->enableActSafe($company);

        $routing = \Mockery::mock(DeliveryRoutingService::class);
        $routing->shouldReceive('geocodeAndSetAddress')->once()->andReturnUsing(function (Order $order, string $address): DeliveryQuote {
            $order->forceFill([
                'delivery_fee_cents' => 801,
                'delivery_address_snapshot' => ['formatted_address' => $address],
            ])->save();
            app(OrderWorkflowService::class)->recalculateTotals($order);

            return new DeliveryQuote(['delivery_fee_cents' => 801]);
        });
        $this->app->instance(DeliveryRoutingService::class, $routing);

        $turn1 = $this->turn($company, 21, 'quero uma marmita', fn (): array => $this->fixture('ORDER_CREATE', [
            'intents' => ['generic_order'], 'subject' => 'product', 'mutates_order' => false,
            'facts_needed' => ['product_details'], 'reply_goal' => 'discover_product',
        ]));
        $this->assertTurnState($turn1, 'SELECT_PRODUCT', 'ASK_PRODUCT');

        $turn2 = $this->turn($company, 22, 'quero uma n8 livre', fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['select_option'], 'subject' => 'product', 'candidate_value' => 'N8 Livre',
            'target_products' => ['n8-tradicional'], 'mutates_order' => true,
            'facts_needed' => ['product_details'], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->assertSame('n8-tradicional', data_get($turn2->payload, 'order_context.draft_order.items.0.menu_item_slug'));
        $this->assertFalse((bool) data_get($turn2->payload, 'order_context.review.required'));

        $turn3 = $this->turn($company, 23, 'qual o buffet de hoje?', fn (): array => $this->fixture('MENU_REQUEST', [
            'intents' => ['ask_product_information'], 'subject' => 'buffet', 'mutates_order' => false,
            'facts_needed' => ['daily_menu'], 'reply_goal' => 'answer_and_continue',
        ]));
        $this->assertSame(data_get($turn2->payload, 'order_context.draft_order'), data_get($turn3->payload, 'order_context.draft_order'));
        $this->assertSame(data_get($turn2->payload, 'order_context.next_objective'), data_get($turn3->payload, 'order_context.next_objective'));

        $turn4 = $this->turn($company, 24, 'arroz feijão macarrão vermelho cenoura beterraba alface e tomate', fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['order_request'], 'subject' => 'order', 'mutates_order' => true,
            'facts_needed' => ['daily_menu'], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->assertTurnState($turn4, 'BUILD_ITEM', 'ASK_MEAT');
        $this->assertFalse((bool) data_get($turn4->payload, 'order_context.review.required'));

        $turn5 = $this->turn($company, 25, 'almôndega e porco', function (array $context): array {
            $draft = (array) data_get($context, 'pending_order_state.draft_order');
            data_set($draft, 'items.0.selections.meats', ['Almôndega', 'Porco']);

            return $this->fixture('ORDER_CONTINUE', [
                'intents' => ['answer_pending_slot'], 'subject' => 'carne',
                'candidate_values' => ['Almôndega', 'Porco'], 'mutates_order' => true,
                'facts_needed' => ['daily_meats'], 'reply_goal' => 'continue_order',
            ], $draft);
        });
        $this->assertTurnState($turn5, 'CONFIRM_ITEM', 'CONFIRM_ITEM');

        $turn6 = $this->turn($company, 26, 'sim, está certo', fn (array $context): array => $this->fixture('ORDER_CONFIRMATION', [
            'intents' => ['confirm'], 'subject' => 'item_confirmation', 'confirmation' => 'yes',
            'mutates_order' => false, 'facts_needed' => ['active_order'], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->assertTurnState($turn6, 'ASK_MORE_ITEMS', 'ASK_MORE_ITEMS');

        $turn7 = $this->turn($company, 27, 'não', fn (array $context): array => $this->fixture('ORDER_CONFIRMATION', [
            'intents' => ['deny'], 'subject' => 'more_items', 'confirmation' => 'no',
            'mutates_order' => false, 'facts_needed' => [], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->assertTurnState($turn7, 'ASK_FULFILLMENT', 'ASK_FULFILLMENT');

        $turn8 = $this->turn($company, 28, 'entrega', fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['select_option'], 'subject' => 'fulfillment', 'candidate_value' => 'Entrega',
            'mutates_order' => true, 'facts_needed' => [], 'reply_goal' => 'continue_order',
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->assertTurnState($turn8, 'ASK_LOCATION', 'ASK_LOCATION');
        $this->assertMatchesRegularExpression('/endere|localiza/', mb_strtolower(implode("\n", (array) data_get($turn8->payload, 'reply_messages'))));

        $turn9 = $this->turn($company, 29, 'Rua do Sol, 123', function (array $context): array {
            $draft = (array) data_get($context, 'pending_order_state.draft_order');
            $draft['fulfillment'] = 'delivery';
            $draft['address'] = 'Rua do Sol, 123';

            return $this->fixture('ORDER_CONTINUE', [
                'intents' => ['answer_pending_slot'], 'subject' => 'address', 'mutates_order' => true,
                'facts_needed' => [], 'reply_goal' => 'continue_order',
            ], $draft);
        });
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION, data_get($turn9->payload, 'decision'), json_encode($turn9->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertTurnState($turn9, 'ASK_PAYMENT_METHOD', 'ASK_PAYMENT_METHOD');
        $this->assertSame(801, (int) Order::query()->sole()->delivery_fee_cents);

        $turn10 = $this->turn($company, 30, 'pix', fn (): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['select_option'], 'subject' => 'payment_method', 'candidate_value' => 'pix',
            'mutates_order' => true, 'facts_needed' => ['payment_methods'], 'reply_goal' => 'wait_payment_proof',
        ]));
        $this->assertTurnState($turn10, 'WAIT_PAYMENT_PROOF', 'WAIT_PAYMENT_PROOF');
        $this->assertSame('prepare_order_payment', data_get($turn10->payload, 'action'), json_encode($turn10->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame(Payment::STATUS_AWAITING_PROOF, Payment::query()->sole()->status);
        $this->assertSame(0, (int) Payment::query()->sole()->confirmed_amount_cents);
        $this->assertFalse((bool) $turn10->requires_human_confirmation);
        $this->assertSame(0, ConversationAlert::query()->currentActionable()->count());
    }

    public function test_meta_turn_loop_discards_an_older_turn_before_dispatch_and_on_retry(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');
        $this->seed([WhatsAppSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        Queue::fake();
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $this->enableActSafe($company);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $draft = [
            'items' => [[
                'menu_item_id' => $product->id,
                'menu_item_slug' => $product->slug,
                'quantity' => 1,
                'selections' => [],
                'removed_components' => [],
                'item_notes' => '',
            ]],
            'fulfillment' => null,
            'address' => '',
            'payment_method' => '',
        ];
        $this->app->instance(ConversationCopilotProviderInterface::class, new class($this->fixture('ORDER_CREATE', ['intents' => ['order_request'], 'subject' => 'product', 'mutates_order' => true, 'facts_needed' => ['product_details'], 'reply_goal' => 'continue_order'], $draft)) implements ConversationCopilotProviderInterface
        {
            public function __construct(private readonly array $fixture) {}

            public function name(): string
            {
                return 'stale-turn-fake';
            }

            public function analyze(array $context): array
            {
                return $this->fixture;
            }
        });

        $webhookA = app(WhatsAppService::class)->storeWebhookEvent($this->payload(101, 'oi'));
        (new ProcessWhatsAppWebhookEvent($webhookA->id))->handle(app(WhatsAppService::class));
        $messageA = Message::query()->where('external_message_id', 'wamid.turn-loop.101')->firstOrFail();
        $messageA->conversation()->update(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);

        $webhookB = app(WhatsAppService::class)->storeWebhookEvent($this->payload(102, 'quero uma N8 Livre'));
        (new ProcessWhatsAppWebhookEvent($webhookB->id))->handle(app(WhatsAppService::class));
        $messageB = Message::query()->where('external_message_id', 'wamid.turn-loop.102')->firstOrFail();
        $conversation = $messageB->conversation()->firstOrFail();
        $conversation->update(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);

        $jobA = new ProcessCopilotAutomation($messageA->id, (int) $conversation->automation_version);
        $jobA->handle(app(CopilotAutomationService::class));
        $stale = AutomationEvent::query()->where('message_id', $messageA->id)->firstOrFail();
        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $stale->status);
        $this->assertContains('stale_inbound_message', data_get($stale->payload, 'reason_codes', []));
        $this->assertTrue((bool) data_get($stale->payload, 'turn_trace.stale_discarded'));
        $this->assertSame(0, Message::query()->where('reply_to_message_id', $messageA->id)->count());

        (new ProcessCopilotAutomation($messageB->id, (int) $conversation->automation_version))
            ->handle(app(CopilotAutomationService::class));
        $this->assertGreaterThanOrEqual(1, Message::query()->where('reply_to_message_id', $messageB->id)->count());
        $this->assertLessThanOrEqual(3, Message::query()->where('reply_to_message_id', $messageB->id)->count());

        $jobA->handle(app(CopilotAutomationService::class));
        $this->assertSame($stale->id, AutomationEvent::query()->where('message_id', $messageA->id)->sole()->id);
        $this->assertSame(0, Message::query()->where('reply_to_message_id', $messageA->id)->count());
        $this->assertSame(
            Message::query()->where('reply_to_message_id', $messageB->id)->count(),
            Message::query()->where('direction', 'outbound')->count(),
        );
    }

    public function test_payment_proof_and_paid_order_have_explicit_terminal_wait_phases(): void
    {
        $analysis = $this->fixture('ORDER_CONTINUE', [
            'intents' => ['ask_product_information'], 'subject' => 'payment', 'mutates_order' => false,
        ]);
        $pending = [
            'has_context' => true,
            'draft_order' => ['items' => [['valid' => true]], 'fulfillment' => 'pickup', 'payment_method' => 'pix'],
            'item_confirmation' => ['status' => 'confirmed'],
            'additional_items' => ['status' => 'declined'],
        ];

        $waiting = app(CopilotTurnStateReducer::class)->reduce($analysis, [
            'pending_order_state' => $pending,
            'active_order' => ['status' => 'payment_proof_received', 'payment_status' => 'unpaid'],
        ]);
        $this->assertSame('WAIT_HUMAN_PAYMENT_CONFIRMATION', $waiting['phase']);
        $this->assertSame('WAIT_HUMAN_PAYMENT_CONFIRMATION', $waiting['next_objective']);

        $complete = app(CopilotTurnStateReducer::class)->reduce($analysis, [
            'pending_order_state' => $pending,
            'active_order' => ['status' => 'confirmed', 'payment_status' => 'paid'],
        ]);
        $this->assertSame('COMPLETE', $complete['phase']);
        $this->assertNull($complete['next_objective']);
    }

    public function test_terminal_sale_starts_a_clean_session_without_erasing_conversation_history(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 13:00:00');
        $this->seed([WhatsAppSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        Queue::fake();
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $this->enableActSafe($company);

        $saleA = $this->turn($company, 81, 'quero uma n8', fn (): array => $this->fixture('ORDER_CREATE', [
            'intents' => ['order_request'],
            'subject' => 'order',
            'mutates_order' => true,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'continue_order',
        ]));
        $sessionA = (string) data_get($saleA->payload, 'order_context.session_id');
        $this->assertNotSame('', $sessionA);

        $terminalPayload = (array) $saleA->payload;
        data_set($terminalPayload, 'order_context.lifecycle', 'complete');
        data_set($terminalPayload, 'order_context.phase', 'COMPLETE');
        data_set($terminalPayload, 'order_context.next_objective', null);
        $saleA->forceFill(['payload' => $terminalPayload])->save();

        $saleB = $this->turn($company, 82, 'quero uma n5 casa', fn (): array => $this->fixture('ORDER_CREATE', [
            'intents' => ['order_request'],
            'subject' => 'order',
            'target_products' => ['n5-casa'],
            'mutates_order' => true,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'continue_order',
        ]));
        $sessionB = (string) data_get($saleB->payload, 'order_context.session_id');

        $this->assertNotSame($sessionA, $sessionB);
        $this->assertSame($sessionB, data_get($saleB->payload, 'order_context.attempt_id'));
        $this->assertSame('open', data_get($saleB->payload, 'order_context.lifecycle'));
        $this->assertNull(data_get($saleB->payload, 'turn_envelope.previous_order_context'));
        $this->assertSame('n5-casa', data_get($saleB->payload, 'order_context.draft_order.items.0.menu_item_slug'), json_encode($saleB->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame([], data_get($saleB->payload, 'order_context.candidate_items'));

        $conversationId = (int) $saleB->conversation_id;
        $this->assertSame(2, Message::query()->where('conversation_id', $conversationId)->where('direction', 'inbound')->count());
        $this->assertTrue(Message::query()->where('conversation_id', $conversationId)->where('content', 'quero uma n8')->exists());
        $this->assertTrue(Message::query()->where('conversation_id', $conversationId)->where('content', 'quero uma n5 casa')->exists());
    }

    private function turn(Company $company, int $sequence, string $text, \Closure $provider): AutomationEvent
    {
        $this->app->instance(ConversationCopilotProviderInterface::class, new class($provider) implements ConversationCopilotProviderInterface
        {
            public function __construct(private readonly \Closure $provider) {}

            public function name(): string
            {
                return 'turn-loop-fake';
            }

            public function analyze(array $context): array
            {
                return ($this->provider)($context);
            }
        });
        $event = app(WhatsAppService::class)->storeWebhookEvent($this->payload($sequence, $text));
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));
        $message = Message::query()->where('external_message_id', 'wamid.turn-loop.'.$sequence)->firstOrFail();
        $message->conversation()->update(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);
        Queue::assertPushed(ProcessCopilotAutomation::class, fn (ProcessCopilotAutomation $job): bool => $job->messageId === $message->id);
        (new ProcessCopilotAutomation($message->id, (int) $message->conversation()->value('automation_version')))
            ->handle(app(CopilotAutomationService::class));

        $result = AutomationEvent::query()
            ->where('message_id', $message->id)
            ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
            ->firstOrFail();
        $this->assertSame(1, AutomationEvent::query()
            ->where('message_id', $message->id)
            ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
            ->count());
        $this->assertSame($message->id, data_get($result->payload, 'turn_envelope.trigger_inbound_message_id'));
        $this->assertSame(data_get($result->payload, 'reply_messages'), data_get($result->payload, 'turn_envelope.final_reply_messages'));
        $this->assertLessThanOrEqual(2, count((array) data_get($result->payload, 'reply_messages')));

        return $result;
    }

    /** @return array<string,mixed> */
    private function fixture(string $intent, array $interpretation, array $draft = []): array
    {
        return [
            'intent' => $intent,
            'confidence' => 0.96,
            'summary' => 'Interpretação do turno de integração.',
            'draft_order' => $draft ?: ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => 'Certo 😊',
            'reply_messages' => ['Certo 😊'],
            'requires_human_review' => true,
            'interpretation' => $interpretation,
        ];
    }

    /** @return array<string,mixed> */
    private function payload(int $sequence, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'fake-business-account-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'fake-phone-number-id', 'display_phone_number' => '15550109999'],
                        'contacts' => [['wa_id' => '15550100077', 'profile' => ['name' => 'Cliente Turn Loop']]],
                        'messages' => [[
                            'id' => 'wamid.turn-loop.'.$sequence,
                            'from' => '15550100077',
                            'timestamp' => (string) (CarbonImmutable::now()->timestamp + $sequence),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function enableActSafe(Company $company): void
    {
        AiAutomationSetting::query()->updateOrCreate(
            ['company_id' => $company->id, 'provider' => CopilotAutomationSettings::PROVIDER],
            [
                'default_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
                'automation_enabled' => true,
                'allow_auto_send' => true,
                'require_human_confirmation_for_ambiguous' => true,
                'require_human_confirmation_for_payments' => true,
                'status' => AiAutomationSetting::STATUS_ACTIVE,
                'settings' => ['rollout' => CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE],
            ],
        );
    }

    private function assertTurnState(AutomationEvent $event, string $phase, string $objective): void
    {
        $this->assertSame($phase, data_get($event->payload, 'order_context.phase'), json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame($objective, data_get($event->payload, 'order_context.next_objective'));
        $this->assertTrue((bool) data_get($event->payload, 'order_context.has_context'));
        $this->assertFalse((bool) data_get($event->payload, 'order_context.review.required'));
    }
}
