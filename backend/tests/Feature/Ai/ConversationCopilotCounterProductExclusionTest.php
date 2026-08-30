<?php

namespace Tests\Feature\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\CopilotMenuAliasResolver;
use App\Services\Ai\CopilotMenuReplyBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationCopilotCounterProductExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_counter_products_are_excluded_from_copilot_context_and_aliases_but_remain_catalog_products(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Balcão',
            'slug' => 'balcao',
            'category_type' => ProductCategory::TYPE_OUTROS,
        ]);
        $counter = Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Geladinho de morango',
            'slug' => 'geladinho-de-morango',
            'product_type' => Product::TYPE_COUNTER,
            'base_price_cents' => 350,
            'is_active' => true,
            'is_available_by_default' => true,
            'metadata' => ['counter_sale' => true],
        ]);
        $normal = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de teste']);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);

        $context = app(ConversationCopilotContextBuilder::class)->forConversation($conversation);
        $menuIds = collect($context['menu'])->pluck('id')->all();

        $this->assertNotContains($counter->id, $menuIds);
        $this->assertContains($normal->id, $menuIds);
        $this->assertNull(app(CopilotMenuAliasResolver::class)->resolve($company, $counter->id, $counter->slug));
        $menuReply = app(CopilotMenuReplyBuilder::class)->build($company, CarbonImmutable::now(), 'cardápio completo');
        $this->assertStringNotContainsString($counter->name, $menuReply['suggested_reply']);
        $this->assertStringContainsString($normal->name, $menuReply['suggested_reply']);
        $this->assertTrue(Product::query()->whereKey($counter->id)->where('product_type', Product::TYPE_COUNTER)->exists());
    }
}
