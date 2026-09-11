<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MenuComponent;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotLatestMessageIntentResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopilotConversationalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_generic_marmita_requests_are_clarified_without_becoming_an_invalid_sku(): void
    {
        $company = $this->seededCompany();

        foreach (['qro uma marmita', 'queria pedir uma marmitex', 'me vê uma marmita'] as $text) {
            $conversation = $this->conversation($company);
            $this->message($conversation, 'inbound', $text);
            $provider = $this->provider(fn (): array => $this->fixture('ORDER_CREATE', [
                'intents' => ['generic_order'],
                'subject' => 'product',
                'mutates_order' => false,
                'facts_needed' => ['product_details'],
                'reply_goal' => 'discover_product',
            ]));
            $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);

            $this->assertSame(1, $provider->calls, $text);
            $this->assertSame('ORDER_CREATE', $result['intent'], $text);
            $this->assertSame([], data_get($result, 'draft_order.items'), $text);
            $this->assertStringContainsStringIgnoringCase('marmit', $result['suggested_reply'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsStringIgnoringCase('não encontrei', $result['suggested_reply'], $text);
            $this->assertStringNotContainsStringIgnoringCase('não existe', $result['suggested_reply'], $text);
            $this->assertFalse($decision['requires_human_review'], $text);
            $this->assertTrue((bool) data_get($result, 'metadata.turn_trace.provider_interpretation_called'), $text);
            $this->assertNotEmpty(data_get($result, 'metadata.resolved_turn.canonical_facts.product_ids'), $text);
        }
    }

    public function test_meat_allowance_is_a_canonical_constraint_and_not_review(): void
    {
        $company = $this->seededCompany();
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');
        [$conversation] = $this->pendingN8Livre($company);
        $draft = (array) data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order');
        data_set($draft, 'items.0.selections.meats', ['Almôndega', 'Churrasco', 'Porco']);
        $text = 'Pode ser almôndega churrasco e porco';
        $this->message($conversation, 'inbound', $text);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['answer_pending_slot'],
            'subject' => 'carne',
            'candidate_values' => ['Almôndega', 'Churrasco', 'Porco'],
            'mutates_order' => true,
            'facts_needed' => ['daily_meats', 'product_details'],
            'reply_goal' => 'apply_commercial_constraint',
        ], $draft)));

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $constraint = data_get($result, 'metadata.constraints.0');

        $this->assertSame('MEAT_ALLOWANCE_EXCEEDED', data_get($constraint, 'code'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame('additional_applied', data_get($constraint, 'resolution'));
        $this->assertGreaterThan(0, (int) data_get($constraint, 'additional_total_cents'));
        $this->assertSame(['apply_canonical_additional', 'remove_selection'], data_get($constraint, 'commercial_options'));
        $this->assertSame(['Almôndega', 'Churrasco', 'Porco'], data_get($result, 'draft_order.items.0.selections.meats'));
        $this->assertFalse($this->decision($conversation, $result, $text)['requires_human_review']);
        $this->assertSame('explain_constraint', data_get($result, 'metadata.turn_trace.next_action'));
    }

    public function test_marmita_discovery_variants_list_the_category_without_product_not_found(): void
    {
        $company = $this->seededCompany();

        foreach ([
            'uma marmitex, quais vocês tem?',
            'quais marmitas vcs tem',
            'tem quais marmitex',
            'quais são as marmitas',
            'que marmita tem hoje',
        ] as $text) {
            $conversation = $this->conversation($company);
            $this->message($conversation, 'inbound', $text);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);

            $this->assertSame('ORDER_CREATE', $result['intent'], $text);
            $this->assertStringContainsString('N8 Casa', $result['suggested_reply'], $text."\n".(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: ''));
            $this->assertStringContainsString('N8 Livre', $result['suggested_reply'], $text);
            $this->assertStringNotContainsStringIgnoringCase('não encontrei', $result['suggested_reply'], $text);
            $this->assertStringNotContainsStringIgnoringCase('não existe', $result['suggested_reply'], $text);
            $this->assertFalse($decision['requires_human_review'], $text);
        }
    }

    public function test_contextual_product_explanation_reaches_semantic_orchestration_instead_of_business_hours(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        $this->message($conversation, 'inbound', 'quero pedir uma marmita');
        $offeredProducts = Product::query()
            ->where('company_id', $company->id)
            ->whereIn('slug', ['n5-casa', 'n8-casa', 'n8-tradicional', 'n9-tradicional'])
            ->get();
        $outbound = 'Claro 😊 Temos N5 Casa, N8 Casa, N8 Livre e N9 Livre. As opções Casa têm composição definida; nas Livre você monta com o buffet do dia.';
        $source = Message::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->pendingEvent(
            $conversation,
            $source,
            ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            ['MENU_ITEM'],
            ['type' => 'choose_option', 'slot' => 'product', 'product_id' => null],
            $offeredProducts->pluck('id')->all(),
        )->forceFill(['payload' => [
            'decision' => CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY,
            'action' => 'send_grounded_reply',
            'waiting_for_customer' => true,
            'assistant_goal' => ['type' => 'choose_option', 'slot' => 'product', 'product_id' => null],
            'conversation_references' => $offeredProducts->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
            ])->values()->all(),
            'order_context' => [
                'has_context' => true,
                'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                'selected_components' => [],
                'missing_fields' => ['MENU_ITEM'],
                'offered_product_ids' => $offeredProducts->pluck('id')->all(),
                'customer_location' => null,
            ],
            'reply_messages' => [$outbound],
        ]])->save();
        $this->message($conversation, 'outbound', $outbound);
        $text = 'como funciona essa N8 Livre?';
        $this->message($conversation, 'inbound', $text);
        $provider = $this->provider(fn (array $context): array => $this->fixture('PRODUCT_CLARIFICATION', [
            'intents' => ['ask_product_information'],
            'subject' => 'product',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => ['n8-tradicional'],
            'reference' => 'recent_options',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['product_details', 'daily_menu', 'daily_meats'],
            'reply_goal' => 'explain_product_and_continue',
            'state_operations' => [],
        ]));
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame(1, $provider->calls, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame('PRODUCT_CLARIFICATION', $result['intent']);
        $this->assertStringContainsString('N8 Livre', $result['suggested_reply']);
        $this->assertStringContainsString('R$ 16,00', $result['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('horário', $result['suggested_reply']);
        $this->assertSame([], data_get($result, 'draft_order.items'));
        $this->assertFalse($this->decision($conversation, $result, $text)['requires_human_review']);
        $this->assertSame($text, data_get($provider->contexts[0], 'latest_message.body'));
        $this->assertSame($outbound, data_get($provider->contexts[0], 'conversation_frame.recent_messages.1.body'));
        $this->assertContains('n8-tradicional', array_column(data_get($provider->contexts[0], 'conversation_frame.business_context.products'), 'slug'));
    }

    public function test_product_explanation_generalizes_beyond_the_word_funciona(): void
    {
        $company = $this->seededCompany();

        foreach (['como é essa N8 Livre?', 'me explica a N8 Livre', 'como monta ela?'] as $text) {
            $conversation = $this->conversation($company);
            $source = $this->message($conversation, 'inbound', 'quero pedir uma marmita');
            $n8Livre = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
            $this->pendingEvent(
                $conversation,
                $source,
                ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                ['MENU_ITEM'],
                ['type' => 'choose_option', 'slot' => 'product', 'product_id' => null],
                [$n8Livre->id],
            );
            $this->message($conversation, 'outbound', 'A N8 Livre é uma das opções que você pode montar com o buffet do dia.');
            $this->message($conversation, 'inbound', $text);
            $provider = $this->provider(fn (): array => $this->fixture('PRODUCT_CLARIFICATION', [
                'intents' => ['ask_product_information'],
                'subject' => 'product',
                'candidate_value' => null,
                'candidate_values' => [],
                'target_products' => ['n8-tradicional'],
                'reference' => 'active_references',
                'selected_option_index' => null,
                'mutates_order' => false,
                'facts_needed' => ['product_details'],
                'reply_goal' => 'explain_product_and_continue',
                'state_operations' => [],
            ]));
            $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

            $result = app(ConversationCopilotService::class)->analyze($conversation);

            $this->assertSame(1, $provider->calls, $text);
            $this->assertSame('PRODUCT_CLARIFICATION', $result['intent'], $text);
            $this->assertStringContainsString('N8 Livre', $result['suggested_reply'], $text);
            $this->assertStringNotContainsStringIgnoringCase('horário', $result['suggested_reply'], $text);
            $this->assertSame([], data_get($result, 'draft_order.items'), $text);
            $this->assertFalse($this->decision($conversation, $result, $text)['requires_human_review'], $text);
        }
    }

    public function test_exact_whatsapp_discovery_explanation_comparison_selection_and_buffet_sequence_preserves_context(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);

        $greetingMessage = $this->message($conversation, 'inbound', 'opa bom dia');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $greeting = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('GREETING', $greeting['intent']);
        $this->readOnlyEvent($conversation, $greetingMessage, $greeting);
        $this->message($conversation, 'outbound', $greeting['suggested_reply']);

        $discoveryMessage = $this->message($conversation, 'inbound', 'quero pedir uma marmita');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $discovery = app(ConversationCopilotService::class)->analyze($conversation);
        $offeredProductIds = (array) data_get($discovery, 'metadata.offered_product_ids', []);
        $this->assertSame('ORDER_CREATE', $discovery['intent']);
        $this->assertStringContainsString('composição definida', $discovery['suggested_reply']);
        $this->assertStringContainsString('buffet disponível no dia', $discovery['suggested_reply']);
        $this->pendingEvent(
            $conversation,
            $discoveryMessage,
            (array) $discovery['draft_order'],
            array_column($discovery['missing_information'], 'code'),
            data_get($discovery, 'metadata.assistant_goal'),
            $offeredProductIds,
        );
        $this->message($conversation, 'outbound', $discovery['suggested_reply']);

        $livreQuestion = $this->message($conversation, 'inbound', 'como funciona essa N8 Livre?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('PRODUCT_CLARIFICATION', [
            'intents' => ['ask_product_information'],
            'subject' => 'product',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => ['n8-tradicional'],
            'reference' => 'recent_options',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'explain_product_and_continue',
            'state_operations' => [],
        ])));
        $livre = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('PRODUCT_CLARIFICATION', $livre['intent']);
        $this->assertStringContainsString('N8 Livre', $livre['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('horário', $livre['suggested_reply']);
        $this->readOnlyEvent($conversation, $livreQuestion, $livre);
        $this->message($conversation, 'outbound', $livre['suggested_reply']);

        $casaQuestion = $this->message($conversation, 'inbound', 'e a N8 Casa?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('PRODUCT_CLARIFICATION', [
            'intents' => ['ask_product_information'],
            'subject' => 'product',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => ['n8-casa'],
            'reference' => 'active_references',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'explain_product_and_continue',
            'state_operations' => [],
        ])));
        $casa = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertStringContainsString('N8 Casa', $casa['suggested_reply']);
        $this->assertCount(2, data_get($casa, 'metadata.conversation_references'));
        $this->readOnlyEvent($conversation, $casaQuestion, $casa);
        $this->message($conversation, 'outbound', $casa['suggested_reply']);

        $comparisonQuestion = $this->message($conversation, 'inbound', 'qual a diferença das duas?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('PRODUCT_CLARIFICATION', [
            'intents' => ['compare_products'],
            'subject' => 'product',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'active_references',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'compare_and_continue',
            'state_operations' => [],
        ])));
        $comparison = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertStringContainsString('N8 Casa', $comparison['suggested_reply']);
        $this->assertStringContainsString('N8 Livre', $comparison['suggested_reply']);
        $this->readOnlyEvent($conversation, $comparisonQuestion, $comparison);
        $this->message($conversation, 'outbound', $comparison['suggested_reply']);

        $selectionMessage = $this->message($conversation, 'inbound', 'pode ser a livre');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['select_option'],
            'subject' => 'product',
            'candidate_value' => 'Livre',
            'candidate_values' => [],
            'target_products' => ['n8-tradicional'],
            'reference' => 'active_references',
            'selected_option_index' => null,
            'mutates_order' => true,
            'facts_needed' => [],
            'reply_goal' => 'continue_order',
            'state_operations' => [],
        ])));
        $selection = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('n8-tradicional', data_get($selection, 'draft_order.items.0.menu_item_slug'), json_encode([
            'selection' => $selection,
            'context' => app(ConversationCopilotContextBuilder::class)->forConversation($conversation),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertFalse($this->decision($conversation, $selection, 'pode ser a livre')['requires_human_review']);
        $this->pendingEvent(
            $conversation,
            $selectionMessage,
            (array) $selection['draft_order'],
            array_column($selection['missing_information'], 'code'),
            data_get($selection, 'metadata.assistant_goal'),
        );
        $this->message($conversation, 'outbound', $selection['suggested_reply']);

        $buffetMessage = $this->message($conversation, 'inbound', 'qual buffet hoje?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $buffet = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('MENU_REQUEST', $buffet['intent']);
        $this->assertStringContainsString('Buffet de hoje', $buffet['suggested_reply']);
        $this->assertSame('n8-tradicional', data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items.0.menu_item_slug'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_pending_n8_livre_survives_menu_and_buffet_questions_and_resumes_the_same_order(): void
    {
        $company = $this->seededCompany();
        [$conversation, $draft, $sourceEvent] = $this->pendingN8Livre($company);
        $menuQuestion = $this->message($conversation, 'inbound', 'qual o cardápio?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

        $menu = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('MENU_REQUEST', $menu['intent']);
        $this->assertStringContainsString('Buffet de hoje', $menu['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('não encontrei', $menu['suggested_reply']);
        $this->assertSame($draft['items'], data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items'));
        $this->pendingEvent($conversation, $menuQuestion, ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''], [], null)
            ->forceFill(['payload' => ['order_context' => ['has_context' => false], 'reply_messages' => [$menu['suggested_reply']]]])
            ->save();
        $this->message($conversation, 'outbound', $menu['suggested_reply']);

        $text = 'Certo, quero montar minha marmita, qual o buffet de hoje?';
        $this->message($conversation, 'inbound', $text);
        $provider = $this->provider(fn (array $context): array => $this->fixture('GENERAL_QUESTION', [
            'intents' => ['ask_product_information'],
            'subject' => 'daily_menu',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'pending_order_state',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['daily_menu'],
            'reply_goal' => 'answer_and_continue',
            'state_operations' => [],
        ], suggestedReply: 'Não encontrei essa opção no cardápio disponível de hoje.'));
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $buffet = app(ConversationCopilotService::class)->analyze($conversation);
        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation);

        $this->assertSame(1, $provider->calls);
        $this->assertStringContainsString('Buffet de hoje', $buffet['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('não encontrei', $buffet['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('qual marmitex', $buffet['suggested_reply']);
        $this->assertSame($sourceEvent->id, data_get($context, 'pending_order_state.source_event_id'));
        $this->assertSame($draft['items'], data_get($context, 'pending_order_state.draft_order.items'));
        $this->assertFalse($this->decision($conversation, $buffet, $text)['requires_human_review'], json_encode($buffet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_pending_n8_livre_merges_a_multi_slot_turn_by_canonical_identity(): void
    {
        $company = $this->seededCompany();
        [$conversation] = $this->pendingN8Livre($company);
        $text = 'arroz feijão macarrão porco e filé na chapa';
        $this->message($conversation, 'inbound', $text);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(function (array $context): array {
            $draft = (array) data_get($context, 'pending_order_state.draft_order');
            data_set($draft, 'items.0.selections.meats', [
                'Porco',
                'Filé de frango na chapa',
                'Porco e Filé de frango na chapa',
            ]);

            return $this->fixture('ORDER_CONTINUE', [
                'intents' => ['other'],
                'subject' => 'order',
                'candidate_value' => null,
                'candidate_values' => [],
                'target_products' => [],
                'reference' => 'pending_order_state',
                'selected_option_index' => null,
                'mutates_order' => true,
                'facts_needed' => ['active_order'],
                'reply_goal' => 'continue_order',
                'state_operations' => [],
            ], $draft);
        }));

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $item = data_get($result, 'draft_order.items.0');
        $componentNames = MenuComponent::query()
            ->whereIn('id', (array) data_get($item, 'daily_component_ids', []))
            ->pluck('name')
            ->all();
        $candidateNames = collect((array) data_get($item, 'daily_component_candidates', []))
            ->flatMap(fn (array $candidate): array => (array) ($candidate['names'] ?? []))
            ->all();
        $candidateIds = collect((array) data_get($item, 'daily_component_candidates', []))
            ->flatMap(fn (array $candidate): array => (array) ($candidate['component_ids'] ?? []))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $meats = (array) data_get($item, 'selections.meats', []);

        $this->assertSame('ORDER_CONTINUE', $result['intent'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame('n8-tradicional', data_get($item, 'menu_item_slug'));
        $this->assertContains('Arroz branco', $componentNames);
        $this->assertNotContains('Arroz amarelo', $componentNames);
        $this->assertContains('Feijão tradicional', $componentNames);
        $this->assertTrue(
            collect([...$componentNames, ...$candidateNames])->contains(fn (string $name): bool => str_starts_with($name, 'Macarrão')),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '',
        );
        $this->assertSame([], array_values(array_intersect($candidateIds, array_map('intval', (array) data_get($item, 'daily_component_ids', [])))));
        $this->assertContains('ACOMPANHAMENTO', array_column($result['missing_information'], 'code'));
        $this->assertStringContainsString('Macarrão vermelho', $result['suggested_reply']);
        $this->assertSame(1, count(array_filter($meats, fn (string $meat): bool => $meat === 'Porco')));
        $this->assertSame(1, count(array_filter($meats, fn (string $meat): bool => $meat === 'Filé de frango na chapa')));
        $this->assertCount(2, $meats);
        $this->assertNotContains('UNRESOLVED_MEAT', array_column($result['warnings'], 'code'));
        $this->assertFalse($this->decision($conversation, $result, $text)['requires_human_review']);
    }

    public function test_pending_accompaniment_options_are_consumed_by_contextual_value_fragment_or_index(): void
    {
        $company = $this->seededCompany();

        foreach ([
            'macarrão vermelho' => ['candidate' => 'macarrão vermelho', 'index' => null, 'expected' => 'Macarrão vermelho'],
            'vermelho' => ['candidate' => 'vermelho', 'index' => null, 'expected' => 'Macarrão vermelho'],
            '1' => ['candidate' => null, 'index' => 1, 'expected' => 'Macarrão vermelho'],
            'alho e óleo' => ['candidate' => 'alho e óleo', 'index' => null, 'expected' => 'Macarrão alho e óleo'],
        ] as $answer => $selection) {
            [$conversation] = $this->pendingN8Livre($company);
            $multiSlotText = 'arroz feijão macarrão porco e filé na chapa';
            $multiSlotMessage = $this->message($conversation, 'inbound', $multiSlotText);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(function (array $context): array {
                $draft = (array) data_get($context, 'pending_order_state.draft_order');
                data_set($draft, 'items.0.selections.meats', ['Porco', 'Filé de frango na chapa']);

                return $this->fixture('ORDER_CONTINUE', [
                    'intents' => ['other'],
                    'subject' => 'order',
                    'candidate_value' => null,
                    'candidate_values' => [],
                    'target_products' => [],
                    'reference' => 'pending_order_state',
                    'selected_option_index' => null,
                    'mutates_order' => true,
                    'facts_needed' => ['daily_menu'],
                    'reply_goal' => 'continue_order',
                    'state_operations' => [],
                ], $draft);
            }));
            $pending = app(ConversationCopilotService::class)->analyze($conversation);
            $this->assertContains('ACOMPANHAMENTO', array_column($pending['missing_information'], 'code'));
            $this->pendingEvent(
                $conversation,
                $multiSlotMessage,
                (array) $pending['draft_order'],
                array_column($pending['missing_information'], 'code'),
                data_get($pending, 'metadata.assistant_goal'),
            );
            $this->message($conversation, 'outbound', 'Anotei o restante 😊 Para o acompanhamento, você prefere Macarrão vermelho ou Macarrão alho e óleo?');
            $this->message($conversation, 'inbound', $answer);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
                'intents' => ['answer_pending_slot'],
                'subject' => 'acompanhamento',
                'candidate_value' => $selection['candidate'],
                'candidate_values' => [],
                'target_products' => [],
                'reference' => 'last_assistant_goal',
                'selected_option_index' => $selection['index'],
                'mutates_order' => true,
                'facts_needed' => ['pending_slot_options'],
                'reply_goal' => 'continue_order',
                'state_operations' => [],
            ])));

            $resolved = app(ConversationCopilotService::class)->analyze($conversation);
            $item = data_get($resolved, 'draft_order.items.0');
            $componentNames = MenuComponent::query()
                ->whereIn('id', (array) data_get($item, 'daily_component_ids', []))
                ->pluck('name')
                ->all();

            $this->assertSame('n8-tradicional', data_get($item, 'menu_item_slug'), $answer);
            $this->assertContains('Arroz branco', $componentNames, $answer);
            $this->assertContains('Feijão tradicional', $componentNames, $answer);
            $this->assertContains($selection['expected'], $componentNames, $answer);
            $this->assertSame(['Porco', 'Filé de frango na chapa'], data_get($item, 'selections.meats'), $answer);
            $this->assertNotContains('ACOMPANHAMENTO', array_column($resolved['missing_information'], 'code'), $answer."\n".(json_encode([
                'item' => $item,
                'metadata' => $resolved['metadata'],
                'warnings' => $resolved['warnings'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: ''));
            $this->assertStringNotContainsString('Macarrão vermelho ou Macarrão alho e óleo', $resolved['suggested_reply'], $answer);
            $this->assertFalse($this->decision($conversation, $resolved, $answer)['requires_human_review'], $answer);
        }
    }

    public function test_pending_meat_option_is_consumed_by_an_unambiguous_fragment(): void
    {
        $company = $this->seededCompany();
        [$conversation] = $this->pendingN8CasaMeat($company);
        $answer = 'frango';
        $this->message($conversation, 'inbound', $answer);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['answer_pending_slot'],
            'subject' => 'carne',
            'candidate_value' => 'frango',
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'last_assistant_goal',
            'selected_option_index' => null,
            'mutates_order' => true,
            'facts_needed' => ['pending_slot_options'],
            'reply_goal' => 'continue_order',
            'state_operations' => [],
        ])));

        $resolved = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('Frango ao molho', data_get($resolved, 'draft_order.items.0.selections.meat'), json_encode([
            'result' => $resolved,
            'context' => app(ConversationCopilotContextBuilder::class)->forConversation($conversation),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame('Beterraba', data_get($resolved, 'draft_order.items.0.selections.salada'));
        $this->assertNotContains('CARNE', array_column($resolved['missing_information'], 'code'));
        $this->assertTrue((bool) data_get($resolved, 'metadata.semantic_delta_validated'));
        $this->assertFalse($this->decision($conversation, $resolved, $answer)['requires_human_review']);
    }

    public function test_plain_arroz_defaults_to_white_and_explicit_yellow_remains_yellow(): void
    {
        $company = $this->seededCompany();

        foreach (['arroz' => 'Arroz branco', 'arroz amarelo' => 'Arroz amarelo'] as $text => $expected) {
            [$conversation] = $this->pendingN8Livre($company);
            $this->message($conversation, 'inbound', $text);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture(
                'ORDER_CONTINUE',
                [
                    'intents' => ['other'],
                    'subject' => 'acompanhamento',
                    'candidate_value' => $text,
                    'candidate_values' => [$text],
                    'target_products' => [],
                    'reference' => 'pending_order_state',
                    'selected_option_index' => null,
                    'mutates_order' => true,
                    'facts_needed' => ['daily_menu'],
                    'reply_goal' => 'continue_order',
                    'state_operations' => [],
                ],
                (array) data_get($context, 'pending_order_state.draft_order'),
            )));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $componentNames = MenuComponent::query()
                ->whereIn('id', (array) data_get($result, 'draft_order.items.0.daily_component_ids', []))
                ->pluck('name')
                ->all();

            $this->assertContains($expected, $componentNames, $text);
            $this->assertSame(1, collect($componentNames)->filter(fn (string $name): bool => str_starts_with($name, 'Arroz'))->count(), $text);
        }
    }

    public function test_e_arroz_is_an_idempotent_contextual_complement_to_the_pending_order(): void
    {
        $company = $this->seededCompany();
        [$conversation, $draft] = $this->pendingN8Livre($company);

        foreach ([1, 2] as $turn) {
            $message = $this->message($conversation, 'inbound', 'e arroz');
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture(
                'ORDER_CONTINUE',
                [
                    'intents' => ['other'],
                    'subject' => 'acompanhamento',
                    'candidate_value' => 'arroz',
                    'candidate_values' => ['arroz'],
                    'target_products' => [],
                    'reference' => 'pending_order_state',
                    'selected_option_index' => null,
                    'mutates_order' => true,
                    'facts_needed' => ['daily_menu'],
                    'reply_goal' => 'continue_order',
                    'state_operations' => [],
                ],
                (array) data_get($context, 'pending_order_state.draft_order'),
            )));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $ids = (array) data_get($result, 'draft_order.items.0.daily_component_ids', []);
            $riceIds = MenuComponent::query()->whereIn('id', $ids)->where('name', 'like', 'Arroz%')->pluck('id')->all();

            $this->assertSame('n8-tradicional', data_get($result, 'draft_order.items.0.menu_item_slug'));
            $this->assertCount(1, $riceIds);
            $this->assertSame('Arroz branco', MenuComponent::query()->findOrFail($riceIds[0])->name);
            $this->assertFalse($this->decision($conversation, $result, 'e arroz')['requires_human_review']);

            $draft = (array) data_get($result, 'draft_order');
            $this->pendingEvent($conversation, $message, $draft, array_column($result['missing_information'], 'code'), data_get($result, 'metadata.assistant_goal'));
            $this->message($conversation, 'outbound', $result['suggested_reply']);
        }
    }

    public function test_repeated_low_confidence_turn_with_valid_pending_state_stays_contextual_and_automatic(): void
    {
        $company = $this->seededCompany();
        [$conversation, $draft] = $this->pendingN8Livre($company);
        $this->message($conversation, 'outbound', 'Desculpe, não consegui entender direitinho. Você quer ver o cardápio, fazer um pedido ou saber algo do restaurante?');
        $text = 'essa aí do jeito normal';
        $this->message($conversation, 'inbound', $text);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('UNKNOWN', [
            'intents' => ['other'],
            'subject' => '',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'ambiguous',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => [],
            'reply_goal' => 'clarify',
            'state_operations' => [],
        ])));

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $decision = $this->decision($conversation, $result, $text);

        $this->assertSame('UNKNOWN', $result['intent']);
        $this->assertStringContainsString('N8 Livre', $result['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('ver o cardápio, fazer um pedido', $result['suggested_reply']);
        $this->assertSame($draft['items'], data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items'));
        $this->assertFalse($decision['requires_human_review']);
    }

    public function test_critical_order_conversation_keeps_one_incremental_state_across_information_and_corrections(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);

        $startMessage = $this->message($conversation, 'inbound', 'quero fazer um pedido');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $start = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('ORDER_CREATE', $start['intent']);
        $this->assertFalse($this->decision($conversation, $start, 'quero fazer um pedido')['requires_human_review']);
        $this->readOnlyEvent($conversation, $startMessage, $start);
        $this->message($conversation, 'outbound', $start['suggested_reply']);

        $discoveryMessage = $this->message($conversation, 'inbound', 'uma marmitex, quais vocês tem?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $discovery = app(ConversationCopilotService::class)->analyze($conversation);
        $offeredProductIds = (array) data_get($discovery, 'metadata.offered_product_ids', []);
        $this->assertSame('ORDER_CREATE', $discovery['intent']);
        $this->assertStringContainsString('N8 Livre', $discovery['suggested_reply']);
        $this->assertStringNotContainsStringIgnoringCase('não encontrei', $discovery['suggested_reply']);
        $this->assertFalse($this->decision($conversation, $discovery, 'uma marmitex, quais vocês tem?')['requires_human_review']);
        $this->pendingEvent(
            $conversation,
            $discoveryMessage,
            ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            ['MENU_ITEM'],
            data_get($discovery, 'metadata.assistant_goal'),
            $offeredProductIds,
        );
        $this->message($conversation, 'outbound', $discovery['suggested_reply']);

        $productMessage = $this->message($conversation, 'inbound', 'n8 livre');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('ORDER_CREATE', [
            'intents' => ['select_product'],
            'subject' => 'product',
            'candidate_value' => 'n8 livre',
            'candidate_values' => [],
            'target_products' => ['n8-tradicional'],
            'reference' => 'offered_products',
            'selected_option_index' => null,
            'mutates_order' => true,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'continue_order',
            'state_operations' => [],
        ], ['items' => [['product' => 'n8livre', 'quantity' => 1, 'selections' => [], 'removed_components' => []]], 'fulfillment' => null])));
        $selected = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('n8-tradicional', data_get($selected, 'draft_order.items.0.menu_item_slug'));
        $this->assertFalse($this->decision($conversation, $selected, 'n8 livre')['requires_human_review']);
        $this->pendingEvent($conversation, $productMessage, (array) $selected['draft_order'], array_column($selected['missing_information'], 'code'), data_get($selected, 'metadata.assistant_goal'));
        $this->message($conversation, 'outbound', $selected['suggested_reply']);

        $menuMessage = $this->message($conversation, 'inbound', 'qual o cardápio?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $menu = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertStringContainsString('Buffet de hoje', $menu['suggested_reply']);
        $this->assertSame('n8-tradicional', data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items.0.menu_item_slug'));
        $this->readOnlyEvent($conversation, $menuMessage, $menu);
        $this->message($conversation, 'outbound', $menu['suggested_reply']);

        $buffetMessage = $this->message($conversation, 'inbound', 'Certo, quero montar minha marmita, qual o buffet de hoje?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('GENERAL_QUESTION', [
            'intents' => ['ask_product_information'],
            'subject' => 'daily_menu',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'pending_order_state',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['daily_menu'],
            'reply_goal' => 'answer_and_continue',
            'state_operations' => [],
        ], suggestedReply: 'Resposta livre não canônica.')));
        $buffet = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertStringContainsString('Buffet de hoje', $buffet['suggested_reply']);
        $this->assertStringContainsString('N8 Livre', $buffet['suggested_reply']);
        $this->assertFalse($this->decision($conversation, $buffet, 'Certo, quero montar minha marmita, qual o buffet de hoje?')['requires_human_review']);
        $this->readOnlyEvent($conversation, $buffetMessage, $buffet);
        $this->message($conversation, 'outbound', $buffet['suggested_reply']);

        $meatQuestion = $this->message($conversation, 'inbound', 'quais carnes?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (): array => $this->fixture('GENERAL_QUESTION', [
            'intents' => ['ask_pending_slot_options'],
            'subject' => 'carne',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'last_assistant_goal',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['pending_slot_options'],
            'reply_goal' => 'list_options',
            'state_operations' => [],
        ], suggestedReply: 'Carne inventada.')));
        $meatOptions = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('semantic_grounded_options', data_get($meatOptions, 'metadata.reply_source'));
        $this->assertStringContainsString('Porco', $meatOptions['suggested_reply']);
        $this->assertStringNotContainsString('Carne inventada', $meatOptions['suggested_reply']);
        $this->readOnlyEvent($conversation, $meatQuestion, $meatOptions);
        $this->message($conversation, 'outbound', $meatOptions['suggested_reply']);

        $multiSlotMessage = $this->message($conversation, 'inbound', 'arroz feijão macarrão porco e filé na chapa');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(function (array $context): array {
            $draft = (array) data_get($context, 'pending_order_state.draft_order');
            data_set($draft, 'items.0.selections.meats', ['Porco', 'Filé de frango na chapa', 'Porco e Filé de frango na chapa']);

            return $this->fixture('ORDER_CONTINUE', [
                'intents' => ['other'],
                'subject' => 'order',
                'candidate_value' => null,
                'candidate_values' => [],
                'target_products' => [],
                'reference' => 'pending_order_state',
                'selected_option_index' => null,
                'mutates_order' => true,
                'facts_needed' => ['daily_menu'],
                'reply_goal' => 'continue_order',
                'state_operations' => [],
            ], $draft);
        }));
        $multiSlot = app(ConversationCopilotService::class)->analyze($conversation);
        $multiItem = data_get($multiSlot, 'draft_order.items.0');
        $componentNames = MenuComponent::query()->whereIn('id', (array) data_get($multiItem, 'daily_component_ids', []))->pluck('name')->all();
        $this->assertSame('n8-tradicional', data_get($multiItem, 'menu_item_slug'));
        $this->assertContains('Arroz branco', $componentNames);
        $this->assertContains('Feijão tradicional', $componentNames);
        $this->assertSame(['Porco', 'Filé de frango na chapa'], data_get($multiItem, 'selections.meats'));
        $this->assertFalse($this->decision($conversation, $multiSlot, 'arroz feijão macarrão porco e filé na chapa')['requires_human_review']);
        $this->pendingEvent($conversation, $multiSlotMessage, (array) $multiSlot['draft_order'], array_column($multiSlot['missing_information'], 'code'), data_get($multiSlot, 'metadata.assistant_goal'));
        $this->message($conversation, 'outbound', $multiSlot['suggested_reply']);

        $cocaMessage = $this->message($conversation, 'inbound', 'tem coca?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $coca = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertStringContainsStringIgnoringCase('coca', $coca['suggested_reply']);
        $this->assertSame([], data_get($coca, 'draft_order.items'));
        $this->readOnlyEvent($conversation, $cocaMessage, $coca);
        $this->message($conversation, 'outbound', $coca['suggested_reply']);

        $riceMessage = $this->message($conversation, 'inbound', 'e arroz');
        $riceContext = app(ConversationCopilotContextBuilder::class)->forConversation($conversation);
        $this->assertSame('e arroz', data_get($riceContext, 'latest_message.body'));
        $this->assertSame('n8-tradicional', data_get($riceContext, 'pending_order_state.draft_order.items.0.menu_item_slug'));
        $this->assertSame('ORDER_CONTINUE', app(CopilotLatestMessageIntentResolver::class)->resolve($riceContext), json_encode($riceContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $riceProvider = $this->provider(fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['other'],
            'subject' => 'acompanhamento',
            'candidate_value' => 'arroz',
            'candidate_values' => ['arroz'],
            'target_products' => [],
            'reference' => 'pending_order_state',
            'selected_option_index' => null,
            'mutates_order' => true,
            'facts_needed' => ['daily_menu'],
            'reply_goal' => 'continue_order',
            'state_operations' => [],
        ], (array) data_get($context, 'pending_order_state.draft_order')));
        $this->app->instance(ConversationCopilotProviderInterface::class, $riceProvider);
        $rice = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame(1, $riceProvider->calls, json_encode(data_get($riceContext, 'pending_clarification'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $riceIds = MenuComponent::query()
            ->whereIn('id', (array) data_get($rice, 'draft_order.items.0.daily_component_ids', []))
            ->where('name', 'like', 'Arroz%')
            ->pluck('id')
            ->all();
        $this->assertCount(1, $riceIds, json_encode($rice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame('Arroz branco', MenuComponent::query()->findOrFail($riceIds[0])->name);
        $this->assertSame('n8-tradicional', data_get($rice, 'draft_order.items.0.menu_item_slug'));
        $riceDecision = $this->decision($conversation, $rice, 'e arroz');
        $this->assertFalse($riceDecision['requires_human_review'], json_encode(['decision' => $riceDecision, 'result' => $rice], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->pendingEvent($conversation, $riceMessage, (array) $rice['draft_order'], array_column($rice['missing_information'], 'code'), data_get($rice, 'metadata.assistant_goal'));
        $this->message($conversation, 'outbound', $rice['suggested_reply']);

        $this->message($conversation, 'inbound', 'quanto fica?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());
        $total = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertSame('ORDER_STATUS', $total['intent']);
        $this->assertStringContainsString('R$ 16,00', $total['suggested_reply']);
        $this->assertSame('n8-tradicional', data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items.0.menu_item_slug'));
        $this->assertFalse($this->decision($conversation, $total, 'quanto fica?')['requires_human_review']);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_semantic_comparison_uses_canonical_facts_and_never_mutates_the_pending_order(): void
    {
        $company = $this->seededCompany();

        foreach (['qual a diferença das n8', 'o que muda da casa pra livre', 'qual delas deixa escolher mais coisa'] as $text) {
            $conversation = $this->conversation($company);
            $this->message($conversation, 'inbound', $text);
            $provider = $this->provider(function (array $context): array {
                return $this->fixture('PRODUCT_CLARIFICATION', [
                    'intents' => ['compare_products'],
                    'subject' => 'product',
                    'candidate_value' => null,
                    'candidate_values' => [],
                    'target_products' => ['n8-casa', 'n8-tradicional'],
                    'reference' => null,
                    'selected_option_index' => null,
                    'mutates_order' => false,
                    'facts_needed' => ['product_details'],
                    'reply_goal' => 'explain_difference',
                    'state_operations' => [],
                ], suggestedReply: 'A N8 Livre custa R$ 16,00.');
            });
            $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);

            $this->assertNotEmpty(data_get($provider->contexts[0], 'conversation_frame.business_context.products'), $text);
            $this->assertSame('PRODUCT_CLARIFICATION', $result['intent'], $text);
            $this->assertSame([], data_get($result, 'draft_order.items'), $text);
            $this->assertStringContainsString('N8 Casa', $result['suggested_reply'], $text);
            $this->assertStringContainsString('N8 Livre', $result['suggested_reply'], $text);
            $this->assertStringContainsString('R$ 13,00', $result['suggested_reply'], $text);
            $this->assertStringContainsString('R$ 16,00', $result['suggested_reply'], $text);
            $this->assertMatchesRegularExpression('/composição|monta|buffet|escolh/iu', $result['suggested_reply'], $text);
            $this->assertSame('semantic_grounded_comparison', data_get($result, 'metadata.reply_source'), $text);
            $this->assertFalse($decision['requires_human_review'], $text);
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_comparison_follow_up_resolves_dessa_from_recent_canonical_references(): void
    {
        $company = $this->seededCompany();
        $casa = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $livre = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $conversation = $this->conversation($company);
        $source = $this->message($conversation, 'inbound', 'qual a diferença das n8');
        AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $source->id,
            'provider' => 'semantic-test',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_DISPATCHED,
            'requires_human_confirmation' => false,
            'payload' => [
                'order_context' => ['has_context' => false],
                'conversation_references' => [
                    ['product_id' => $casa->id, 'slug' => $casa->slug, 'name' => $casa->name],
                    ['product_id' => $livre->id, 'slug' => $livre->slug, 'name' => $livre->name],
                ],
            ],
            'processed_at' => now(),
        ]);
        $this->message($conversation, 'outbound', 'A N8 Livre é a opção para montar com o buffet do dia.');
        $text = 'sim mas qual a diferença dessa pra n8 casa?';
        $this->message($conversation, 'inbound', $text);
        $provider = $this->provider(function (array $context): array {
            $this->assertSame(['n8-casa', 'n8-tradicional'], array_column(data_get($context, 'conversation_frame.active_references', []), 'slug'));

            return $this->fixture('PRODUCT_CLARIFICATION', [
                'intents' => ['compare_products'],
                'subject' => 'product',
                'candidate_value' => null,
                'candidate_values' => [],
                'target_products' => ['n8-casa', 'n8-tradicional'],
                'reference' => 'dessa',
                'selected_option_index' => null,
                'mutates_order' => false,
                'facts_needed' => ['product_details'],
                'reply_goal' => 'explain_difference',
                'state_operations' => [],
            ]);
        });
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('PRODUCT_CLARIFICATION', $result['intent']);
        $this->assertStringContainsString('N8 Casa', $result['suggested_reply']);
        $this->assertStringContainsString('N8 Livre', $result['suggested_reply']);
        $this->assertSame([], data_get($result, 'draft_order.items'));
    }

    public function test_last_assistant_goal_lists_pending_slot_options_without_losing_state(): void
    {
        $company = $this->seededCompany();

        foreach (['quais que tem?', 'quais tem aí', 'me fala as opções'] as $text) {
            [$conversation, $draft] = $this->pendingN8CasaSalad($company);
            $this->message($conversation, 'inbound', $text);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(function (array $context): array {
                $this->assertSame('choose_option', data_get($context, 'conversation_frame.last_assistant_goal.type'));
                $this->assertSame('salada', data_get($context, 'conversation_frame.last_assistant_goal.slot'));
                $this->assertContains('Beterraba', array_column((array) data_get($context, 'conversation_frame.last_assistant_goal.allowed_values'), 'label'));

                return $this->fixture('GENERAL_QUESTION', [
                    'intents' => ['ask_pending_slot_options'],
                    'subject' => 'salada',
                    'candidate_value' => null,
                    'candidate_values' => [],
                    'target_products' => [],
                    'reference' => 'last_assistant_goal',
                    'selected_option_index' => null,
                    'mutates_order' => false,
                    'facts_needed' => ['pending_slot_options'],
                    'reply_goal' => 'list_options',
                    'state_operations' => [],
                ], suggestedReply: 'Tem rúcula e tomate seco.');
            }));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);

            $this->assertSame([], data_get($result, 'draft_order.items'), $text);
            $this->assertStringContainsString('Beterraba', $result['suggested_reply'], $text);
            $this->assertStringNotContainsString('rúcula', mb_strtolower($result['suggested_reply']), $text);
            $this->assertSame('semantic_grounded_options', data_get($result, 'metadata.reply_source'), $text);
            $this->assertSame($draft['items'], data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items'), $text);
            $this->assertFalse($decision['requires_human_review'], $text);
        }
    }

    public function test_semantic_pending_slot_answer_is_applied_only_from_a_canonical_allowed_value(): void
    {
        $company = $this->seededCompany();

        foreach (['beterraba', 'pode ser beterraba', 'a beterraba'] as $text) {
            [$conversation] = $this->pendingN8CasaSalad($company);
            $this->message($conversation, 'inbound', $text);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
                'intents' => ['answer_pending_slot'],
                'subject' => 'salada',
                'candidate_value' => 'Beterraba',
                'candidate_values' => [],
                'target_products' => [],
                'reference' => 'last_assistant_goal',
                'selected_option_index' => null,
                'mutates_order' => true,
                'facts_needed' => ['pending_slot_options'],
                'reply_goal' => 'continue_order',
                'state_operations' => [],
            ])));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);

            $this->assertSame('Beterraba', data_get($result, 'draft_order.items.0.selections.salada'), $text);
            $this->assertSame('Almôndega', data_get($result, 'draft_order.items.0.selections.meat'), $text);
            $this->assertNotContains('SALADA', array_column($result['missing_information'], 'code'), $text);
            $this->assertTrue((bool) data_get($result, 'metadata.semantic_delta_validated'), $text);
            $this->assertFalse($decision['requires_human_review'], $text);
        }
    }

    public function test_information_question_during_a_pending_order_is_read_only_and_context_resumes_after_it(): void
    {
        $company = $this->seededCompany();
        [$conversation, $draft, $sourceEvent] = $this->pendingN8CasaSalad($company);
        $question = $this->message($conversation, 'inbound', 'quanto custa a n9?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

        $result = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('PRODUCT_CLARIFICATION', $result['intent']);
        $this->assertSame([], data_get($result, 'draft_order.items'));
        $this->assertStringContainsString('N9', $result['suggested_reply']);
        $this->assertSame($draft['items'], data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items'));

        AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $question->id,
            'provider' => 'test',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_DISPATCHED,
            'requires_human_confirmation' => false,
            'payload' => ['order_context' => ['has_context' => false], 'reply_messages' => [$result['suggested_reply']]],
            'processed_at' => now(),
        ]);
        $this->message($conversation, 'outbound', $result['suggested_reply']);
        $this->message($conversation, 'inbound', 'continua a minha');

        $resumed = app(ConversationCopilotContextBuilder::class)->forConversation($conversation);
        $this->assertSame($sourceEvent->id, data_get($resumed, 'pending_order_state.source_event_id'));
        $this->assertSame($draft['items'], data_get($resumed, 'pending_order_state.draft_order.items'));
    }

    public function test_product_availability_and_pending_total_are_grounded_read_only_turns_in_the_same_order(): void
    {
        $company = $this->seededCompany();
        [$conversation, $draft, $sourceEvent] = $this->pendingN8Livre($company);
        $availabilityQuestion = $this->message($conversation, 'inbound', 'tem coca?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

        $availability = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame('PRODUCT_CLARIFICATION', $availability['intent']);
        $this->assertSame([], data_get($availability, 'draft_order.items'));
        $this->assertStringContainsStringIgnoringCase('coca', $availability['suggested_reply']);
        $this->assertFalse($this->decision($conversation, $availability, 'tem coca?')['requires_human_review']);
        $this->assertSame($draft['items'], data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items'));

        AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $availabilityQuestion->id,
            'provider' => 'test',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_DISPATCHED,
            'requires_human_confirmation' => false,
            'payload' => ['order_context' => ['has_context' => false], 'reply_messages' => [$availability['suggested_reply']]],
            'processed_at' => now(),
        ]);
        $this->message($conversation, 'outbound', $availability['suggested_reply']);
        $this->message($conversation, 'inbound', 'quanto fica?');
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->providerMustNotRun());

        $total = app(ConversationCopilotService::class)->analyze($conversation);
        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation);

        $this->assertSame('ORDER_STATUS', $total['intent'], json_encode($total, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame([], data_get($total, 'draft_order.items'));
        $this->assertStringContainsString('R$ 16,00', $total['suggested_reply']);
        $this->assertSame(1600, data_get($total, 'metadata.order_total_cents'));
        $this->assertSame($sourceEvent->id, data_get($context, 'pending_order_state.source_event_id'));
        $this->assertSame($draft['items'], data_get($context, 'pending_order_state.draft_order.items'));
        $this->assertFalse($this->decision($conversation, $total, 'quanto fica?')['requires_human_review']);
    }

    public function test_semantic_pending_order_product_replacement_preserves_other_items_without_duplication(): void
    {
        $company = $this->seededCompany();
        $n8 = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $coca = Product::query()->where('company_id', $company->id)->where('slug', 'coca-cola-600ml')->firstOrFail();
        $water = Product::query()->where('company_id', $company->id)->where('slug', 'agua-mineral')->firstOrFail();
        foreach (['não quero mais coca coloca agua', 'troca a coca por agua'] as $text) {
            $conversation = $this->conversation($company);
            $source = $this->message($conversation, 'inbound', 'uma n8 casa com almondega e coca 600');
            $draft = ['items' => [
                $this->draftItem($n8, ['meat' => 'Almôndega', 'salada' => 'Beterraba']),
                $this->draftItem($coca),
            ], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
            $this->pendingEvent($conversation, $source, $draft, [], null);
            $this->message($conversation, 'outbound', 'Anotei a Coca-Cola 600 ml.');
            $this->message($conversation, 'inbound', $text);
            $provider = $this->provider(fn (array $context): array => $this->fixture('ORDER_CHANGE', [
                'intents' => ['correct_order'],
                'subject' => 'product',
                'candidate_value' => null,
                'candidate_values' => [],
                'target_products' => [],
                'reference' => 'pending_order_state',
                'selected_option_index' => null,
                'mutates_order' => true,
                'facts_needed' => ['product_details'],
                'reply_goal' => 'continue_order',
                'state_operations' => [[
                    'operation' => 'replace',
                    'subject' => 'product',
                    'from' => 'coca-cola-600ml',
                    'to' => 'agua-mineral',
                ]],
            ]));
            $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);
            $slugs = array_column(data_get($result, 'draft_order.items', []), 'menu_item_slug');

            $this->assertSame(['n8-casa', 'coca-cola-600ml'], array_column(data_get($provider->contexts[0], 'pending_order_state.draft_order.items', []), 'menu_item_slug'));
            $this->assertSame('ORDER_CONTINUE', $result['intent'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertContains('n8-casa', $slugs);
            $this->assertNotContains('coca-cola-600ml', $slugs);
            $this->assertSame(1, count(array_filter($slugs, fn (string $slug): bool => $slug === 'agua-mineral')), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertSame($water->id, data_get(collect(data_get($result, 'draft_order.items'))->firstWhere('menu_item_slug', 'agua-mineral'), 'menu_item_id'));
            $this->assertTrue((bool) data_get($result, 'metadata.semantic_delta_validated'));
            $this->assertFalse($decision['requires_human_review']);
        }
    }

    public function test_recent_option_reference_resolves_an_ordinal_through_the_generic_pending_slot_contract(): void
    {
        $company = $this->seededCompany();

        foreach (['a segunda', '2', 'essa segunda'] as $text) {
            [$conversation] = $this->pendingN8CasaMeat($company);
            $this->message($conversation, 'inbound', $text);
            $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
                'intents' => ['answer_pending_slot'],
                'subject' => 'carne',
                'candidate_value' => null,
                'candidate_values' => [],
                'target_products' => [],
                'reference' => 'recent_options',
                'selected_option_index' => 2,
                'mutates_order' => true,
                'facts_needed' => ['pending_slot_options'],
                'reply_goal' => 'continue_order',
                'state_operations' => [],
            ])));

            $result = app(ConversationCopilotService::class)->analyze($conversation);
            $decision = $this->decision($conversation, $result, $text);

            $this->assertSame('Porco', data_get($result, 'draft_order.items.0.selections.meat'), $text);
            $this->assertTrue((bool) data_get($result, 'metadata.semantic_delta_validated'), $text);
            $this->assertFalse($decision['requires_human_review'], $text);
        }
    }

    public function test_unknown_fast_path_uses_semantic_recovery_before_clarification_or_review(): void
    {
        $company = $this->seededCompany();
        $conversation = $this->conversation($company);
        $text = 'me explica qual dessas duas combina mais com quem quer montar tudo';
        $this->message($conversation, 'inbound', $text);
        $provider = $this->provider(fn (array $context): array => $this->fixture('PRODUCT_CLARIFICATION', [
            'intents' => ['compare_products'],
            'subject' => 'product',
            'candidate_value' => null,
            'candidate_values' => [],
            'target_products' => ['n8-casa', 'n8-tradicional'],
            'reference' => 'recent_options',
            'selected_option_index' => null,
            'mutates_order' => false,
            'facts_needed' => ['product_details'],
            'reply_goal' => 'explain_tradeoff',
            'state_operations' => [],
        ]));
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $decision = $this->decision($conversation, $result, $text);

        $this->assertSame(1, $provider->calls);
        $this->assertTrue((bool) data_get($result, 'metadata.semantic_recovery'));
        $this->assertSame('semantic_grounded_comparison', data_get($result, 'metadata.reply_source'));
        $this->assertNotSame('UNKNOWN', $result['intent']);
        $this->assertFalse($decision['requires_human_review']);
    }

    public function test_invalid_pending_slot_value_is_clarified_with_canonical_options_without_review(): void
    {
        $company = $this->seededCompany();
        [$conversation] = $this->pendingN8CasaSalad($company);
        $text = 'pode ser rúcula';
        $this->message($conversation, 'inbound', $text);
        $this->app->instance(ConversationCopilotProviderInterface::class, $this->provider(fn (array $context): array => $this->fixture('ORDER_CONTINUE', [
            'intents' => ['answer_pending_slot'],
            'subject' => 'salada',
            'candidate_value' => 'Rúcula',
            'candidate_values' => [],
            'target_products' => [],
            'reference' => 'last_assistant_goal',
            'selected_option_index' => null,
            'mutates_order' => true,
            'facts_needed' => ['pending_slot_options'],
            'reply_goal' => 'clarify_option',
            'state_operations' => [],
        ])));

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $decision = $this->decision($conversation, $result, $text);

        $this->assertNull(data_get($result, 'draft_order.items.0.selections.salada'));
        $this->assertContains('SALADA', array_column($result['missing_information'], 'code'), json_encode([
            'result' => $result,
            'context' => app(ConversationCopilotContextBuilder::class)->forConversation($conversation),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertContains('DOMAIN_SELECTION_REJECTED', array_column($result['warnings'], 'code'));
        $this->assertStringContainsString('Beterraba', $result['suggested_reply']);
        $this->assertStringNotContainsString('Rúcula', $result['suggested_reply']);
        $this->assertFalse($decision['requires_human_review']);
    }

    public function test_provider_failure_preserves_state_and_asks_a_safe_clarification_before_review(): void
    {
        $company = $this->seededCompany();
        [$conversation, $draft] = $this->pendingN8CasaSalad($company);
        $text = 'essa do jeito que você falou';
        $this->message($conversation, 'inbound', $text);
        $provider = $this->provider(function (): array {
            throw new \RuntimeException('semantic provider offline');
        });
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $result = app(ConversationCopilotService::class)->analyze($conversation);
        $decision = $this->decision($conversation, $result, $text);

        $this->assertSame('UNKNOWN', $result['intent']);
        $this->assertTrue((bool) data_get($result, 'metadata.provider_failure'));
        $this->assertSame(1, $provider->calls);
        $this->assertSame($draft['items'], data_get(app(ConversationCopilotContextBuilder::class)->forConversation($conversation), 'pending_order_state.draft_order.items'));
        $this->assertNotSame('', $result['suggested_reply']);
        $this->assertFalse($decision['requires_human_review']);
    }

    /** @return array{Conversation,array<string,mixed>,AutomationEvent} */
    private function pendingN8CasaSalad(Company $company): array
    {
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $conversation = $this->conversation($company);
        $source = $this->message($conversation, 'inbound', 'eu quero uma n8 casa com almondega');
        $draft = ['items' => [$this->draftItem($product, ['meat' => 'Almôndega'])], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        $goal = ['type' => 'choose_option', 'slot' => 'salada', 'product_id' => $product->id];
        $event = $this->pendingEvent($conversation, $source, $draft, ['SALADA'], $goal);
        $this->message($conversation, 'outbound', 'Qual salada você deseja?');

        return [$conversation, $draft, $event];
    }

    /** @return array{Conversation,array<string,mixed>,AutomationEvent} */
    private function pendingN8CasaMeat(Company $company): array
    {
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-casa')->firstOrFail();
        $conversation = $this->conversation($company);
        $source = $this->message($conversation, 'inbound', 'eu quero uma n8 casa');
        $draft = ['items' => [$this->draftItem($product, ['salada' => 'Beterraba'])], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        $goal = ['type' => 'choose_option', 'slot' => 'carne', 'product_id' => $product->id];
        $event = $this->pendingEvent($conversation, $source, $draft, ['CARNE'], $goal);
        $this->message($conversation, 'outbound', "Escolha a carne:\n1. Almôndega\n2. Porco\n3. Frango ao molho");

        return [$conversation, $draft, $event];
    }

    /** @return array{Conversation,array<string,mixed>,AutomationEvent} */
    private function pendingN8Livre(Company $company): array
    {
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $conversation = $this->conversation($company);
        $source = $this->message($conversation, 'inbound', 'n8 livre');
        $draft = ['items' => [$this->draftItem($product)], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        $goal = ['type' => 'choose_option', 'slot' => 'carne', 'product_id' => $product->id];
        $event = $this->pendingEvent($conversation, $source, $draft, ['CARNE'], $goal);
        $this->message($conversation, 'outbound', 'Qual carne você deseja?');

        return [$conversation, $draft, $event];
    }

    private function pendingEvent(Conversation $conversation, Message $source, array $draft, array $missing, ?array $goal, array $offeredProductIds = []): AutomationEvent
    {
        return AutomationEvent::query()->create([
            'company_id' => $conversation->company_id,
            'conversation_id' => $conversation->id,
            'message_id' => $source->id,
            'provider' => 'test',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_DISPATCHED,
            'requires_human_confirmation' => false,
            'payload' => [
                'decision' => CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY,
                'action' => 'send_grounded_reply',
                'waiting_for_customer' => true,
                'assistant_goal' => $goal,
                'conversation_references' => Product::query()
                    ->whereIn('id', $offeredProductIds)
                    ->get()
                    ->map(fn (Product $product): array => [
                        'product_id' => (int) $product->id,
                        'slug' => (string) $product->slug,
                        'name' => (string) $product->name,
                    ])->values()->all(),
                'order_context' => [
                    'has_context' => true,
                    'draft_order' => $draft,
                    'selected_components' => [],
                    'missing_fields' => $missing,
                    'offered_product_ids' => $offeredProductIds,
                    'customer_location' => null,
                ],
            ],
            'processed_at' => now(),
        ]);
    }

    private function readOnlyEvent(Conversation $conversation, Message $source, array $result): AutomationEvent
    {
        return AutomationEvent::query()->create([
            'company_id' => $conversation->company_id,
            'conversation_id' => $conversation->id,
            'message_id' => $source->id,
            'provider' => 'test',
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => AutomationEvent::STATUS_DISPATCHED,
            'requires_human_confirmation' => false,
            'payload' => [
                'assistant_goal' => data_get($result, 'metadata.assistant_goal'),
                'conversation_references' => array_values((array) data_get($result, 'metadata.conversation_references', [])),
                'order_context' => ['has_context' => false],
                'reply_messages' => [$result['suggested_reply']],
            ],
            'processed_at' => now(),
        ]);
    }

    private function draftItem(Product $product, array $selections = []): array
    {
        return [
            'menu_item_id' => $product->id,
            'menu_item_slug' => $product->slug,
            'quantity' => 1,
            'selections' => $selections,
            'removed_components' => [],
            'item_notes' => '',
        ];
    }

    private function fixture(string $intent, array $interpretation, array $draft = [], string $suggestedReply = ''): array
    {
        return [
            'intent' => $intent,
            'confidence' => 0.93,
            'summary' => 'Interpretação semântica do turno.',
            'draft_order' => $draft ?: ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $suggestedReply,
            'reply_messages' => $suggestedReply === '' ? [] : [$suggestedReply],
            'requires_human_review' => true,
            'interpretation' => $interpretation,
        ];
    }

    private function provider(\Closure $callback): ConversationCopilotProviderInterface
    {
        return new class($callback) implements ConversationCopilotProviderInterface
        {
            public int $calls = 0;

            public array $contexts = [];

            public function __construct(private readonly \Closure $callback) {}

            public function name(): string
            {
                return 'semantic-test';
            }

            public function analyze(array $context): array
            {
                $this->calls++;
                $this->contexts[] = $context;

                return ($this->callback)($context);
            }
        };
    }

    private function providerMustNotRun(): ConversationCopilotProviderInterface
    {
        return $this->provider(function (): array {
            throw new \LogicException('Provider must not run for this deterministic fast path.');
        });
    }

    private function decision(Conversation $conversation, array $result, string $text): array
    {
        return app(CopilotAutomationAuthorityPolicy::class)->decide(
            $conversation->forceFill(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]),
            $result,
            CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
            true,
            $text,
        );
    }

    private function message(Conversation $conversation, string $direction, string $content): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => $direction === 'inbound' ? 'customer' : 'assistant',
            'direction' => $direction,
            'content' => $content,
            'type' => 'text',
            $direction === 'inbound' ? 'received_at' : 'sent_at' => now(),
        ]);
    }

    private function conversation(Company $company): Conversation
    {
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente conversacional']);

        return Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
            'started_at' => now(),
        ]);
    }

    private function seededCompany(): Company
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }
}
