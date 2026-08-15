<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\ConversationCopilotService;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationCopilotTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_is_read_only_and_resolves_only_available_company_products(): void
    {
        $company = Company::query()->create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender' => 'customer', 'content' => 'Quero uma N5.', 'type' => 'text', 'received_at' => now()]);
        $category = ProductCategory::query()->create(['company_id' => $company->id, 'name' => 'Marmitas', 'slug' => 'marmitas']);
        $product = Product::query()->create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'N5 Casa', 'slug' => 'n5-casa', 'product_type' => 'marmita', 'base_price_cents' => 800, 'currency' => 'BRL', 'is_active' => true, 'is_available_by_default' => true]);
        $this->app->instance(ConversationCopilotProviderInterface::class, new FakeConversationCopilotProvider(['intent' => 'ORDER_CREATE', 'confidence' => 0.9, 'draft_order' => ['items' => [['menu_item_slug' => 'n5', 'quantity' => 1, 'removed_components' => ['sushi'], 'item_notes' => 'Pouco feijao']], 'fulfillment' => 'delivery'], 'missing_information' => [], 'warnings' => [], 'suggested_reply' => 'Qual e o endereco?']));
        $before = ['orders' => Order::count(), 'messages' => Message::count(), 'payments' => Payment::count()];

        $analysis = app(ConversationCopilotService::class)->analyze($conversation);

        $this->assertSame(1, $analysis['schema_version']);
        $this->assertSame($product->id, $analysis['draft_order']['items'][0]['menu_item_id']);
        $this->assertSame([], $analysis['draft_order']['items'][0]['removed_components']);
        $this->assertContains('INVALID_REMOVAL', array_column($analysis['warnings'], 'code'));
        $this->assertTrue($analysis['requires_human_review']);
        $this->assertSame($before, ['orders' => Order::count(), 'messages' => Message::count(), 'payments' => Payment::count()]);
    }

    public function test_company_cannot_analyze_another_company_conversation(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $company = Company::query()->create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $other = Company::query()->create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create(['company_id' => $other->id, 'name' => 'Outra empresa']);
        $conversation = Conversation::query()->create([
            'company_id' => $other->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/copilot/analyze")
            ->assertNotFound();
    }
}
