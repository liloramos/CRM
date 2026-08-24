<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Orders\OrderWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationCopilotContextBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_manual_n8_without_meat_does_not_block_the_next_n8_pork_proposal(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00');

        try {
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
            $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
            $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
            $n8 = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
            $orders = app(OrderWorkflowService::class);

            foreach (['quero uma N8 Livre sem carne', 'sem salada tambem'] as $offset => $content) {
                $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $content, 'type' => 'text', 'received_at' => now()]);
                $time = CarbonImmutable::parse('2026-07-06 11:'.(50 + $offset).':00');
                $message->forceFill(['created_at' => $time, 'updated_at' => $time])->save();
            }

            $cancelledOrder = $orders->createDraft($company, ['payer_customer_id' => $customer->id]);
            $orders->addItem($cancelledOrder, $n8, [
                'options' => [[
                    'name' => 'Sem carne',
                    'option_type' => 'included_choice',
                    'group_code' => 'carne',
                    'metadata' => ['source' => 'explicit_no_meat', 'meat_mode' => 'none'],
                ]],
            ]);
            $cancelledOrder->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => CarbonImmutable::parse('2026-07-06 11:55:00'),
            ])->save();

            $current = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero uma N8 de porco', 'type' => 'text', 'received_at' => now()]);
            $current->forceFill(['created_at' => CarbonImmutable::parse('2026-07-06 12:00:00'), 'updated_at' => CarbonImmutable::parse('2026-07-06 12:00:00')])->save();

            $provider = new class implements ConversationCopilotProviderInterface
            {
                /** @var array<string,mixed> */
                public array $receivedContext = [];

                public function name(): string
                {
                    return 'fake';
                }

                /** @param array<string,mixed> $context @return array<string,mixed> */
                public function analyze(array $context): array
                {
                    $this->receivedContext = $context;

                    return [
                        'intent' => 'ORDER_CREATE',
                        'draft_order' => ['items' => [[
                            'product' => 'n8-casa',
                            'quantity' => 1,
                            'selections' => ['meat' => 'porco', 'salada' => 'vinagrete'],
                            'removed_components' => [],
                        ]], 'fulfillment' => null],
                        'missing_information' => [['code' => 'SALADA', 'label' => 'Salada']],
                        'warnings' => [],
                        'suggested_reply' => 'Qual salada voce deseja na N8: repolho com tomate, vinagrete, beterraba ou cenoura?',
                    ];
                }
            };
            $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

            $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $item = $analysis['draft_order']['items'][0];

            $this->assertSame(Order::STATUS_CANCELLED, $cancelledOrder->fresh()->status);
            $this->assertNotNull($cancelledOrder->fresh()->cancelled_at);
            $this->assertTrue($provider->receivedContext['cycle_boundary']['applied']);
            $this->assertSame('closed_customer_order', $provider->receivedContext['cycle_boundary']['source']);
            $this->assertNull($provider->receivedContext['active_order']);
            $this->assertSame(['quero uma N8 de porco'], array_column($provider->receivedContext['messages'], 'body'));
            $this->assertSame('n8-tradicional', $item['menu_item_slug']);
            $this->assertSame('traditional', $item['selections']['meat_mode'] ?? 'traditional');
            $this->assertSame(['Porco'], $item['selections']['meats']);
            $this->assertSame('READY', $analysis['proposal']['applyability']);
            $this->assertSame([], array_column($analysis['missing_information'], 'code'));
            $this->assertSame([], array_column($analysis['warnings'], 'code'));
            $this->assertDoesNotMatchRegularExpression('/salada|vinagrete/i', $analysis['suggested_reply']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_closed_conversation_order_excludes_the_previous_order_cycle_from_context(): void
    {
        $company = Company::query()->create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        $oldMessage = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero uma N5 de porco', 'type' => 'text', 'received_at' => now()]);
        $oldMessage->forceFill(['created_at' => CarbonImmutable::parse('2026-08-18 10:00:00'), 'updated_at' => CarbonImmutable::parse('2026-08-18 10:00:00')])->save();
        $order = app(OrderWorkflowService::class)->createDraft($company, ['conversation_id' => $conversation->id, 'payer_customer_id' => $customer->id]);
        $order->forceFill(['status' => Order::STATUS_FINISHED, 'finished_at' => CarbonImmutable::parse('2026-08-18 10:05:00')])->save();
        $conversation->forceFill(['active_order_id' => $order->id])->save();
        foreach (['quero uma N8 Casa de porco', 'qual salada?', 'pode ser vinagrete'] as $offset => $content) {
            $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => $offset === 1 ? 'agent' : 'customer', 'direction' => $offset === 1 ? 'outbound' : 'inbound', 'content' => $content, 'type' => 'text', 'received_at' => now()]);
            $time = CarbonImmutable::parse('2026-08-18 10:0'.(6 + $offset).':00');
            $message->forceFill(['created_at' => $time, 'updated_at' => $time])->save();
        }

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

        $this->assertTrue($context['cycle_boundary']['applied']);
        $this->assertSame('closed_conversation_order', $context['cycle_boundary']['source']);
        $this->assertNull($context['active_order']);
        $this->assertSame(['quero uma N8 Casa de porco', 'qual salada?', 'pode ser vinagrete'], array_column($context['messages'], 'body'));
        $this->assertNotContains('quero uma N5 de porco', array_column($context['messages'], 'body'));
    }

    public function test_current_multiturn_messages_are_not_cut_when_no_closed_order_exists(): void
    {
        $company = Company::query()->create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        foreach (['quero uma N8 Casa de porco', 'qual salada?', 'pode ser vinagrete'] as $content) {
            Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $content, 'type' => 'text', 'received_at' => now()]);
        }

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation);

        $this->assertFalse($context['cycle_boundary']['applied']);
        $this->assertCount(3, $context['messages']);
    }

    public function test_cancelled_manual_customer_order_starts_a_new_copilot_cycle_without_reusing_its_selections(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 19:23:00', 'America/Sao_Paulo'));
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);

        foreach (['quero uma N8 Casa de porco', 'pode ser vinagrete'] as $offset => $content) {
            $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $content, 'type' => 'text', 'received_at' => now()]);
            $time = CarbonImmutable::parse($offset === 0 ? '2026-08-19 19:19:00' : '2026-08-19 19:20:00');
            $message->forceFill(['created_at' => $time, 'updated_at' => $time])->save();
        }

        $order = app(OrderWorkflowService::class)->createDraft($company, ['payer_customer_id' => $customer->id]);
        $order->forceFill([
            'status' => Order::STATUS_CANCELLED,
            'cancelled_at' => CarbonImmutable::parse('2026-08-19 19:21:00'),
        ])->save();

        $currentMessage = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'n8 so bife com porco', 'type' => 'text', 'received_at' => now()]);
        $currentMessage->forceFill(['created_at' => CarbonImmutable::parse('2026-08-19 19:23:00'), 'updated_at' => CarbonImmutable::parse('2026-08-19 19:23:00')])->save();

        $provider = new class implements ConversationCopilotProviderInterface
        {
            /** @var array<string,mixed> */
            public array $receivedContext = [];

            public function name(): string
            {
                return 'fake';
            }

            /** @param array<string,mixed> $context @return array<string,mixed> */
            public function analyze(array $context): array
            {
                $this->receivedContext = $context;

                return [
                    'intent' => 'ORDER_CREATE',
                    'draft_order' => ['items' => [[
                        'product' => 'n8',
                        'quantity' => 1,
                        'selections' => ['meat_mode' => 'beef_only', 'meats' => ['porco'], 'salads' => ['vinagrete']],
                        'removed_components' => [],
                    ]], 'fulfillment' => null],
                    'missing_information' => [],
                    'warnings' => [],
                    'suggested_reply' => 'Você quer o N8 Casa ou o N8 Livre?',
                ];
            }
        };
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());
        $item = $analysis['draft_order']['items'][0];

        $this->assertSame('closed_customer_order', $provider->receivedContext['cycle_boundary']['source']);
        $this->assertSame(['n8 so bife com porco'], array_column($provider->receivedContext['messages'], 'body'));
        $this->assertNull($provider->receivedContext['active_order']);
        $this->assertSame('n8-tradicional', $item['menu_item_slug']);
        $this->assertNotContains('Vinagrete', $item['selections']['salads'] ?? []);
        $this->assertContains('CARNE', array_column($analysis['missing_information'], 'code'));
        $this->assertContains('CONFLICTING_MEAT_REQUEST', array_column($analysis['warnings'], 'code'));
        $this->assertSame('Você quer somente bife ou porco com bife adicional?', $analysis['suggested_reply']);
        $this->assertSame('Falta escolher a carne.', $analysis['proposal']['missing_information'][0]['message']);
        $this->assertSame('O cliente informou opções de carne incompatíveis.', $analysis['proposal']['warnings'][0]['message']);
        CarbonImmutable::setTestNow();
    }

    public function test_restaurante_sol_starts_a_new_operational_context_at_midnight_without_an_active_order(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 00:50:00', 'America/Sao_Paulo'));

        try {
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
            $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
            $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
            $previous = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero uma N8 de porco pra entrega', 'type' => 'text', 'received_at' => now()]);
            $previous->forceFill(['created_at' => CarbonImmutable::parse('2026-08-20 21:43:00', 'America/Sao_Paulo'), 'updated_at' => CarbonImmutable::parse('2026-08-20 21:43:00', 'America/Sao_Paulo')])->save();
            $current = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'quero uma N8 de porco e uma N5 sem carne', 'type' => 'text', 'received_at' => now()]);
            $current->forceFill(['created_at' => CarbonImmutable::parse('2026-08-21 00:50:00', 'America/Sao_Paulo'), 'updated_at' => CarbonImmutable::parse('2026-08-21 00:50:00', 'America/Sao_Paulo')])->save();

            $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

            $this->assertSame('operational_day', $context['cycle_boundary']['source']);
            $this->assertSame('America/Sao_Paulo', $context['cycle_boundary']['company_timezone']);
            $this->assertSame('00:00', $context['cycle_boundary']['operational_day_start_time']);
            $this->assertSame(['quero uma N8 de porco e uma N5 sem carne'], array_column($context['messages'], 'body'));
            $this->assertStringNotContainsString('entrega', $context['latest_message']['body']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_late_night_company_keeps_messages_before_midnight_in_the_operational_day_started_at_four_am(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 02:00:00', 'America/Sao_Paulo'));

        try {
            $company = $this->companyWithOperationalDayStart('04:00');
            $conversation = $this->conversationFor($company);
            $this->messageAt($conversation, 'pedido atual', '2026-08-21 01:00:00');
            $this->messageAt($conversation, 'continuacao', '2026-08-21 02:00:00');

            $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

            $this->assertSame('operational_day', $context['cycle_boundary']['source']);
            $this->assertSame('04:00', $context['cycle_boundary']['operational_day_start_time']);
            $this->assertSame(['pedido atual', 'continuacao'], array_column($context['messages'], 'body'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_late_night_company_excludes_previous_cycle_after_four_am_without_an_active_order(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 04:10:00', 'America/Sao_Paulo'));

        try {
            $company = $this->companyWithOperationalDayStart('04:00');
            $conversation = $this->conversationFor($company);
            $this->messageAt($conversation, 'mensagem antiga', '2026-08-21 03:50:00');
            $this->messageAt($conversation, 'novo ciclo', '2026-08-21 04:10:00');

            $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

            $this->assertSame(['novo ciclo'], array_column($context['messages'], 'body'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_active_order_keeps_its_context_across_the_operational_day_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 00:02:00', 'America/Sao_Paulo'));

        try {
            $company = $this->companyWithOperationalDayStart('00:00');
            $conversation = $this->conversationFor($company);
            $order = app(OrderWorkflowService::class)->createDraft($company, ['conversation_id' => $conversation->id, 'payer_customer_id' => $conversation->customer_id]);
            $order->forceFill(['created_at' => CarbonImmutable::parse('2026-08-20 23:58:00', 'America/Sao_Paulo')])->save();
            $conversation->forceFill(['active_order_id' => $order->id])->save();
            $this->messageAt($conversation, 'pedido antes da virada', '2026-08-20 23:58:00');
            $this->messageAt($conversation, 'continuacao depois da virada', '2026-08-21 00:02:00');

            $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

            $this->assertSame('active_order_cycle', $context['cycle_boundary']['source']);
            $this->assertSame(['pedido antes da virada', 'continuacao depois da virada'], array_column($context['messages'], 'body'));
            $this->assertSame($order->code, $context['active_order']['code']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_active_order_context_excludes_messages_from_before_its_cycle(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 15:00:00', 'America/Sao_Paulo'));

        try {
            $company = $this->companyWithOperationalDayStart('00:00');
            $conversation = $this->conversationFor($company);
            $this->messageAt($conversation, 'pedido antigo N8 Casa com vinagrete', '2026-08-20 19:00:00');
            $order = app(OrderWorkflowService::class)->createDraft($company, ['conversation_id' => $conversation->id, 'payer_customer_id' => $conversation->customer_id]);
            $order->forceFill(['created_at' => CarbonImmutable::parse('2026-08-21 14:53:00', 'America/Sao_Paulo')])->save();
            $conversation->forceFill(['active_order_id' => $order->id])->save();
            $this->messageAt($conversation, 'quero também uma N5 sem carne', '2026-08-21 14:54:00');

            $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation->fresh());

            $this->assertSame('active_order_cycle', $context['cycle_boundary']['source']);
            $this->assertSame(['quero também uma N5 sem carne'], array_column($context['messages'], 'body'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_rejected_n8_casa_is_recovered_per_item_without_losing_the_grounded_n5_without_meat(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00:00', 'America/Sao_Paulo'));

        try {
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
            $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
            $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
            $this->messageAt($conversation, 'quero uma N8 de porco e uma N5 sem carne', '2026-08-21 12:00:00');

            $provider = new class implements ConversationCopilotProviderInterface
            {
                public function name(): string
                {
                    return 'fake';
                }

                /** @param array<string,mixed> $context @return array<string,mixed> */
                public function analyze(array $context): array
                {
                    return [
                        'intent' => 'ORDER_CREATE',
                        'draft_order' => ['items' => [
                            ['product' => 'n8-casa', 'quantity' => 1, 'selections' => ['meat' => 'porco']],
                            ['product' => 'n5', 'quantity' => 1, 'selections' => ['meat_mode' => 'none']],
                        ]],
                        'missing_information' => [['code' => 'CARNE', 'label' => 'Carnes']],
                        'warnings' => [],
                        'suggested_reply' => 'Qual carne o cliente deseja?',
                    ];
                }
            };
            $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

            $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $items = collect($analysis['draft_order']['items'])->keyBy('menu_item_slug');

            $this->assertCount(2, $items);
            $this->assertSame(['Porco'], $items['n8-tradicional']['selections']['meats']);
            $this->assertSame('traditional', $items['n8-tradicional']['selections']['meat_mode'] ?? 'traditional');
            $this->assertSame('none', $items['n5-casa']['selections']['meat_mode']);
            $this->assertNotContains('CARNE', array_column($analysis['missing_information'], 'code'));
            $this->assertNotContains('UNGROUNDED_PRODUCT', array_column($analysis['warnings'], 'code'));
            $this->assertStringStartsWith('Entendi:', $analysis['suggested_reply']);
            $this->assertStringNotContainsString('o cliente deseja', $analysis['suggested_reply']);
            $this->assertSame('READY', $analysis['proposal']['applyability']);
            $this->assertSame('NEW_ORDER', $analysis['proposal']['target']['state']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @param list<array<string,mixed>> $providerItems @param list<string> $expectedOrder */
    #[DataProvider('explicitMultiItemProviderShapes')]
    public function test_explicit_multi_item_recovery_preserves_each_product_without_duplicates(array $providerItems, string $inbound, array $expectedOrder): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00:00', 'America/Sao_Paulo'));

        try {
            $this->seed(SolRestaurantStructuredMenuSeeder::class);
            $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
            $conversation = $this->conversationFor($company);
            $this->messageAt($conversation, $inbound, '2026-08-21 12:00:00');
            $this->app->instance(ConversationCopilotProviderInterface::class, new class($providerItems) implements ConversationCopilotProviderInterface
            {
                /** @param list<array<string,mixed>> $items */
                public function __construct(private readonly array $items) {}

                public function name(): string
                {
                    return 'fake';
                }

                /** @param array<string,mixed> $context @return array<string,mixed> */
                public function analyze(array $context): array
                {
                    return [
                        'intent' => 'ORDER_CREATE',
                        'draft_order' => ['items' => $this->items, 'fulfillment' => null],
                        'missing_information' => [['code' => 'CARNE', 'label' => 'Carnes']],
                        'warnings' => [],
                        'suggested_reply' => 'Qual carne o cliente deseja?',
                    ];
                }
            });

            $analysis = app(ConversationCopilotService::class)->analyze($conversation->fresh());
            $items = collect($analysis['draft_order']['items'])->keyBy('menu_item_slug');

            $this->assertCount(2, $items);
            $this->assertSame(['n5-casa', 'n8-tradicional'], $items->keys()->sort()->values()->all());
            $this->assertSame($expectedOrder, array_column($analysis['proposal']['items'], 'menu_item_slug'));
            $this->assertSame(['Porco'], $items['n8-tradicional']['selections']['meats']);
            $this->assertSame('none', $items['n5-casa']['selections']['meat_mode']);
            $this->assertNotContains('CARNE', array_column($analysis['missing_information'], 'code'));
            $this->assertStringStartsWith('Entendi:', $analysis['suggested_reply']);
            $this->assertStringNotContainsString('o cliente deseja', $analysis['suggested_reply']);
            $this->assertSame('READY', $analysis['proposal']['applyability']);
            $this->assertSame('NEW_ORDER', $analysis['proposal']['target']['state']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array<string,array{0:list<array<string,mixed>>,1:string,2:list<string>}> */
    public static function explicitMultiItemProviderShapes(): array
    {
        $n8 = ['product' => 'n8', 'quantity' => 1, 'selections' => ['meats' => ['porco']]];
        $n8Casa = ['product' => 'n8-casa', 'quantity' => 1, 'selections' => ['meat' => 'porco']];
        $n5 = ['product' => 'n5', 'quantity' => 1, 'selections' => ['meat_mode' => 'none']];

        return [
            'both products correct' => [[$n8, $n5], 'quero uma N8 de porco e uma N5 sem carne', ['n8-tradicional', 'n5-casa']],
            'n8 casa rejected and recovered' => [[$n8Casa, $n5], 'quero uma N8 de porco e uma N5 sem carne', ['n8-tradicional', 'n5-casa']],
            'only n5 returned' => [[$n5], 'quero uma N8 de porco e uma N5 sem carne', ['n8-tradicional', 'n5-casa']],
            'only n8 returned' => [[$n8], 'quero uma N8 de porco e uma N5 sem carne', ['n8-tradicional', 'n5-casa']],
            'no items returned' => [[], 'quero uma N8 de porco e uma N5 sem carne', ['n8-tradicional', 'n5-casa']],
            'products returned in reverse order' => [[$n5, $n8], 'quero uma N8 de porco e uma N5 sem carne', ['n8-tradicional', 'n5-casa']],
            'inbound n5 before n8' => [[$n8, $n5], 'quero uma N5 sem carne e uma N8 de porco', ['n5-casa', 'n8-tradicional']],
        ];
    }

    private function companyWithOperationalDayStart(string $start): Company
    {
        $company = Company::query()->create(['name' => 'Pizzaria', 'slug' => 'pizzaria-'.str_replace(':', '', $start)]);
        $company->setting()->create(['timezone' => 'America/Sao_Paulo', 'settings' => ['operational_day_start_time' => $start]]);

        return $company->fresh('setting');
    }

    private function conversationFor(Company $company): Conversation
    {
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);

        return Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
    }

    private function messageAt(Conversation $conversation, string $content, string $at): void
    {
        $timestamp = CarbonImmutable::parse($at, 'America/Sao_Paulo');
        $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => $content, 'type' => 'text', 'received_at' => $timestamp]);
        $message->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();
    }
}
