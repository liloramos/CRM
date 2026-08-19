<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Data\Ai\CopilotEvaluationResult;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\Product;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotPipeline;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\CopilotEvaluationDataset;
use App\Services\Ai\CopilotEvaluationScorer;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationCopilotEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_evaluation_dataset_is_read_only_and_rejects_unsafe_drafts(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => Customer::query()->create(['company_id' => $company->id, 'name' => 'Avaliacao'])->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);
        $before = [
            'orders' => Order::count(),
            'items' => OrderItem::count(),
            'payments' => Payment::count(),
            'outbound_messages' => Message::query()->where('direction', 'outbound')->count(),
            'print_jobs' => PrintJob::count(),
            'mode' => $conversation->refresh()->automation_mode,
        ];

        $result = new CopilotEvaluationResult(count($this->fixtures()));
        $scorer = app(CopilotEvaluationScorer::class);
        foreach ($this->fixtures() as $fixture) {
            foreach ($fixture['messages'] ?? [$fixture['input']] as $offset => $message) {
                Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'customer',
                    'direction' => 'inbound',
                    'content' => $message,
                    'type' => 'text',
                    'received_at' => now()->addSeconds($offset),
                ]);
            }
            $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider($fixture['provider']));

            $date = CarbonImmutable::parse($fixture['evaluation_date']);
            $analysis = app(ConversationCopilotPipeline::class)->analyze(
                $company,
                app(ConversationCopilotContextBuilder::class)->forMessages($company, collect($fixture['messages'] ?? [$fixture['input']])->map(fn (string $message): array => ['direction' => 'inbound', 'type' => 'text', 'body' => $message])->all(), null, $date),
                $date,
            )['safe'];
            $this->assertTrue($analysis['requires_human_review'], $fixture['id']);
            if (array_key_exists('expected_item_count', $fixture)) {
                $this->assertCount($fixture['expected_item_count'], $analysis['draft_order']['items'] ?? [], $fixture['id'].' expected item count');
            }
            $scores = $scorer->score($fixture, $analysis);
            $intentCorrect = $scores['intent'];
            $this->assertTrue($intentCorrect, $fixture['id'].' '.$fixture['category'].' intent');

            if (isset($fixture['product'])) {
                $item = $analysis['draft_order']['items'][0] ?? [];
                $productCorrect = $scores['product'];
                $this->assertTrue($productCorrect, $fixture['id'].' '.$fixture['category'].' product');
                if (($fixture['expected_quantity'] ?? null) !== null) {
                    $quantityCorrect = $scores['quantity'];
                    $this->assertTrue($quantityCorrect, $fixture['id'].' '.$fixture['category'].' quantity');
                }
                if (isset($fixture['expected_selections'])) {
                    $selectionCorrect = $scores['selections'];
                    $this->assertTrue($selectionCorrect, $fixture['id'].' '.$fixture['category'].' selections');
                }
                if (isset($fixture['expected_notes'])) {
                    $notesCorrect = $scores['notes'];
                    $this->assertTrue($notesCorrect, $fixture['id'].' '.$fixture['category'].' notes');
                }
                if (isset($fixture['expected_removals'])) {
                    $removalsCorrect = $scores['removals'];
                    $this->assertTrue($removalsCorrect, $fixture['id'].' '.$fixture['category'].' removals');
                }
            }
            if (isset($fixture['expected_products'])) {
                $products = array_column($analysis['draft_order']['items'] ?? [], 'menu_item_slug');
                $this->assertSame($fixture['expected_products'], $products, $fixture['id'].' '.$fixture['category'].' items');
            }
            foreach ($fixture['warnings'] ?? [] as $warning) {
                $this->assertContains($warning, array_column($analysis['warnings'], 'code'), $fixture['name']);
            }
            foreach ($fixture['missing'] ?? [] as $missing) {
                $this->assertContains($missing, array_column($analysis['missing_information'], 'code'), $fixture['name']);
            }
            $fulfillmentCorrect = $scores['fulfillment'];
            $missingCorrect = $scores['missing_information'];
            $this->assertTrue($fulfillmentCorrect, $fixture['id'].' '.$fixture['category'].' fulfillment');
            $this->assertTrue($missingCorrect, $fixture['id'].' '.$fixture['category'].' missing');
            $safetyPassed = $scores['safety'];
            $this->assertTrue($safetyPassed, $fixture['id'].' '.$fixture['category'].' safety');

            foreach ($scores as $metric => $score) {
                if ($score !== null) {
                    $result->record($metric, $score);
                }
            }
        }

        $this->assertSame(63, $result->totalCases);
        $this->assertTrue($result->meets($scorer->thresholds()), json_encode($result->scores()));
        $this->assertSame($before, [
            'orders' => Order::count(),
            'items' => OrderItem::count(),
            'payments' => Payment::count(),
            'outbound_messages' => Message::query()->where('direction', 'outbound')->count(),
            'print_jobs' => PrintJob::count(),
            'mode' => $conversation->refresh()->automation_mode,
        ]);
    }

    public function test_invalid_provider_output_and_cross_company_product_are_safe(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'Teste', 'type' => 'text', 'received_at' => now()]);
        $foreignProduct = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail()->replicate();
        $foreignProduct->company_id = $other->id;
        $foreignProduct->slug = 'n5-estrangeira';
        $foreignProduct->save();

        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider(['intent' => 'ORDER_CREATE', 'draft_order' => ['items' => [['menu_item_id' => $foreignProduct->id, 'quantity' => 1]], 'fulfillment' => null]]));
        $foreign = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertContains('UNRESOLVED_MENU_ITEM', array_column($foreign['warnings'], 'code'));

        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider(['unexpected' => true]));
        $invalid = app(ConversationCopilotService::class)->analyze($conversation);
        $this->assertContains('INVALID_PROVIDER_OUTPUT', array_column($invalid['warnings'], 'code'));
        $this->assertTrue($invalid['requires_human_review']);
    }

    public function test_provider_cannot_inject_price_payment_or_order_status(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'direction' => 'inbound', 'content' => 'N5 de porco.', 'type' => 'text', 'received_at' => now()]);
        $before = [Order::count(), Payment::count(), Message::query()->where('direction', 'outbound')->count()];

        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider([
            'intent' => 'ORDER_CREATE',
            'price_cents' => 1,
            'payment_approved' => true,
            'order_status' => 'finished',
            'draft_order' => [
                'items' => [[
                    'product' => 'n5',
                    'quantity' => 1,
                    'price_cents' => 1,
                    'selections' => ['meat' => 'porco', 'salada_casa' => 'beterraba'],
                ]],
                'fulfillment' => null,
                'mark_paid' => true,
                'delivery_status' => 'delivered',
            ],
        ]));

        $analysis = app(ConversationCopilotService::class)->analyze($conversation);
        $item = $analysis['draft_order']['items'][0];
        $this->assertSame(800, $item['unit_price_cents']);
        $this->assertArrayNotHasKey('price_cents', $item);
        $this->assertArrayNotHasKey('payment_approved', $analysis);
        $this->assertArrayNotHasKey('order_status', $analysis);
        $this->assertArrayNotHasKey('mark_paid', $analysis['draft_order']);
        $this->assertArrayNotHasKey('delivery_status', $analysis['draft_order']);
        $this->assertSame($before, [Order::count(), Payment::count(), Message::query()->where('direction', 'outbound')->count()]);
        $this->assertTrue($analysis['requires_human_review']);
    }

    /** @return list<array<string,mixed>> */
    private function fixtures(): array
    {
        return CopilotEvaluationDataset::cases();
    }
}
