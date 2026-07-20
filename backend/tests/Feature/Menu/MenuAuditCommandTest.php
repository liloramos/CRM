<?php

namespace Tests\Feature\Menu;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_audit_command_does_not_modify_menu_records(): void
    {
        $company = Company::query()->create([
            'name' => 'Sol Restaurante',
            'slug' => 'restaurante-sol',
        ]);

        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Marmitas',
            'slug' => 'marmitas',
            'category_type' => ProductCategory::TYPE_MARMITAS,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'N5 Casa',
            'slug' => 'n5-casa',
            'product_type' => Product::TYPE_MARMITA,
            'menu_rule_code' => 'n5_casa',
            'base_price_cents' => 800,
            'currency' => 'BRL',
            'is_active' => true,
            'is_available_by_default' => true,
        ]);

        $before = $this->menuRecordCounts();

        $this->artisan('menu:audit', [
            '--restaurant' => 'restaurante-sol',
            '--format' => 'json',
        ])->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $this->menuRecordCounts());
    }

    /**
     * @return array<string, int>
     */
    private function menuRecordCounts(): array
    {
        return [
            'product_categories' => DB::table('product_categories')->count(),
            'products' => DB::table('products')->count(),
            'product_options' => DB::table('product_options')->count(),
            'weekly_menus' => DB::table('weekly_menus')->count(),
            'weekly_menu_items' => DB::table('weekly_menu_items')->count(),
            'daily_menu_overrides' => DB::table('daily_menu_overrides')->count(),
            'daily_menu_option_overrides' => DB::table('daily_menu_option_overrides')->count(),
        ];
    }
}
