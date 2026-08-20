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
    }
}
