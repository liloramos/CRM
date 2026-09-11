<?php

namespace Tests\Feature\Menu;

use App\Console\Commands\RestoreSolMenuCommand;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroupComponent;
use App\Models\ProductOptionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolRestaurantMenuRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_missing_catalog_without_writing(): void
    {
        $this->artisan('sol:restore-menu', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('menu_components', 0);
    }

    public function test_command_restores_the_versioned_official_catalog(): void
    {
        $this->artisan('sol:restore-menu')->assertSuccessful();

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();

        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'slug' => 'n5-casa',
            'name' => 'N5 Casa',
            'base_price_cents' => 800,
        ]);
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'slug' => 'n8-tradicional',
            'name' => 'N8 Livre',
            'base_price_cents' => 1600,
        ]);
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'slug' => 'n9-tradicional',
            'name' => 'N9 Livre',
            'base_price_cents' => 1900,
        ]);
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'slug' => 'separadinha',
            'name' => 'Separadinha',
            'base_price_cents' => 2000,
        ]);
        $this->assertDatabaseHas('menu_components', [
            'company_id' => $company->id,
            'slug' => 'file-de-peixe',
            'name' => 'Filé de peixe empanado',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('menu_components', [
            'company_id' => $company->id,
            'slug' => 'bisteca-de-porco-na-chapa',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('product_group_components', [
            'final_price_cents' => 2000,
            'requires_confirmation' => false,
        ]);
        $this->assertSame(
            RestoreSolMenuCommand::CATALOG_VERSION,
            data_get(CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->settings, 'menu_catalog.version'),
        );
        $this->assertSame(6, Product::query()
            ->where('company_id', $company->id)
            ->where('slug', 'separadinha')
            ->firstOrFail()
            ->serviceDays()
            ->where('is_active', true)
            ->count());

        $countsBeforeForcedRerun = [
            'products' => Product::query()->where('company_id', $company->id)->count(),
            'option_groups' => ProductOptionGroup::query()->where('company_id', $company->id)->count(),
            'component_links' => ProductGroupComponent::query()
                ->whereHas('group', fn ($query) => $query->where('company_id', $company->id))
                ->count(),
        ];

        $this->artisan('sol:restore-menu', ['--force-official' => true])->assertSuccessful();

        $this->assertSame($countsBeforeForcedRerun, [
            'products' => Product::query()->where('company_id', $company->id)->count(),
            'option_groups' => ProductOptionGroup::query()->where('company_id', $company->id)->count(),
            'component_links' => ProductGroupComponent::query()
                ->whereHas('group', fn ($query) => $query->where('company_id', $company->id))
                ->count(),
        ]);
    }

    public function test_rerun_preserves_manual_and_unknown_records_without_force(): void
    {
        $this->artisan('sol:restore-menu')->assertSuccessful();

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $n5 = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $n5->update(['name' => 'Nome revisado manualmente']);
        $category = ProductCategory::query()->where('company_id', $company->id)->firstOrFail();
        Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Produto local mantido',
            'slug' => 'produto-local-mantido',
            'product_type' => Product::TYPE_MARMITA,
            'base_price_cents' => 1234,
            'currency' => 'BRL',
            'is_active' => true,
            'is_available_by_default' => true,
            'allows_item_notes' => true,
            'display_order' => 999,
        ]);

        $this->artisan('sol:restore-menu')->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'id' => $n5->id,
            'name' => 'Nome revisado manualmente',
        ]);
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'slug' => 'produto-local-mantido',
        ]);
    }

    public function test_partial_catalog_requires_explicit_force_and_never_deletes_unknown_products(): void
    {
        $this->artisan('sol:restore-menu')->assertSuccessful();

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        Product::query()->where('company_id', $company->id)->where('slug', 'separadinha')->delete();

        $this->artisan('sol:restore-menu')->assertFailed();
        $this->assertDatabaseMissing('products', [
            'company_id' => $company->id,
            'slug' => 'separadinha',
        ]);

        $this->artisan('sol:restore-menu', ['--force-official' => true])->assertSuccessful();
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'slug' => 'separadinha',
            'base_price_cents' => 2000,
        ]);
    }
}
