<?php

namespace Tests\Feature\Menu;

use App\Models\Company;
use App\Models\DailyMenuOverride;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Menu\MenuAvailabilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_seed_creates_official_products_and_rules(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $this->assertDatabaseHas('products', [
            'slug' => 'n5-casa',
            'base_price_cents' => 800,
            'menu_rule_code' => 'n5_casa',
        ]);

        $this->assertDatabaseHas('products', [
            'slug' => 'n8-casa',
            'base_price_cents' => 1300,
            'menu_rule_code' => 'n8_casa',
        ]);

        $this->assertDatabaseHas('products', [
            'slug' => 'n8-tradicional',
            'base_price_cents' => 1600,
            'menu_rule_code' => 'n8_tradicional',
        ]);

        $this->assertDatabaseHas('products', [
            'slug' => 'n9-tradicional',
            'base_price_cents' => 1800,
            'menu_rule_code' => 'n9_tradicional',
        ]);

        $this->assertDatabaseHas('product_options', [
            'slug' => 'ovo-frito',
            'option_type' => ProductOption::TYPE_ADDON,
            'price_delta_cents' => 200,
        ]);
    }

    public function test_available_menu_excludes_daily_unavailable_items(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $product = Product::query()->where('slug', 'n8-casa')->firstOrFail();
        $date = CarbonImmutable::create(2026, 7, 6);

        DailyMenuOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'availability_date' => $date,
            'status' => DailyMenuOverride::STATUS_UNAVAILABLE,
            'reason' => 'Item esgotado no dia.',
        ]);

        $availableSlugs = app(MenuAvailabilityService::class)
            ->availableProducts($company, $date)
            ->pluck('slug')
            ->all();

        $this->assertNotContains('n8-casa', $availableSlugs);
        $this->assertContains('n5-casa', $availableSlugs);
    }

    public function test_available_menu_endpoint_uses_only_active_available_products(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        Product::query()->where('slug', 'latinha')->update(['is_active' => false]);

        $response = $this->getJson('/api/restaurants/restaurante-sol/menu/available?date=2026-07-06');

        $response->assertOk();
        $response->assertJsonMissing(['slug' => 'latinha']);
        $response->assertJsonFragment(['slug' => 'n5-casa']);
    }
}
