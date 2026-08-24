<?php

namespace Tests\Feature\Menu;

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroupComponent;
use App\Models\ProductOptionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AddJuiceFlavorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_flavor_link_once_and_preserves_other_flavors(): void
    {
        [$company, $product, $group] = $this->juiceCatalog();
        $other = MenuComponent::query()->create([
            'company_id' => $company->id,
            'name' => 'Goiaba',
            'slug' => 'goiaba',
            'component_type' => 'juice_flavor',
            'is_active' => true,
        ]);
        ProductGroupComponent::query()->create([
            'product_option_group_id' => $group->id,
            'menu_component_id' => $other->id,
            'is_active' => true,
        ]);

        $arguments = ['flavor' => 'Laranja', '--company' => $company->slug, '--product' => $product->slug, '--group' => 'sabor'];
        $this->assertSame(0, Artisan::call('menu:add-juice-flavor', $arguments));
        $this->assertSame(0, Artisan::call('menu:add-juice-flavor', $arguments));

        $orange = MenuComponent::query()->where('company_id', $company->id)->where('slug', 'laranja')->firstOrFail();
        $this->assertSame(1, MenuComponent::query()->where('company_id', $company->id)->where('slug', 'laranja')->count());
        $this->assertSame(1, ProductGroupComponent::query()->where('product_option_group_id', $group->id)->where('menu_component_id', $orange->id)->count());
        $this->assertDatabaseHas('product_group_components', ['product_option_group_id' => $group->id, 'menu_component_id' => $other->id]);
    }

    public function test_dry_run_and_invalid_company_do_not_persist(): void
    {
        [$company, $product] = $this->juiceCatalog();
        $arguments = ['flavor' => 'Laranja', '--company' => $company->slug, '--product' => $product->slug, '--group' => 'sabor', '--dry-run' => true];

        $this->assertSame(0, Artisan::call('menu:add-juice-flavor', $arguments));
        $this->assertDatabaseMissing('menu_components', ['company_id' => $company->id, 'slug' => 'laranja']);
        $this->assertSame(1, Artisan::call('menu:add-juice-flavor', [...$arguments, '--company' => 'missing-company']));
    }

    /** @return array{Company,Product,ProductOptionGroup} */
    private function juiceCatalog(): array
    {
        $company = Company::query()->create(['name' => 'Restaurante Sol', 'slug' => 'restaurante-sol']);
        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Bebidas',
            'slug' => 'bebidas',
            'category_type' => ProductCategory::TYPE_BEBIDAS,
        ]);
        $product = Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Suco',
            'slug' => 'suco',
            'product_type' => Product::TYPE_JUICE,
            'base_price_cents' => 700,
            'is_active' => true,
            'is_available_by_default' => true,
        ]);
        $group = ProductOptionGroup::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'code' => 'sabor',
            'label' => 'Sabor',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
        ]);

        return [$company, $product, $group];
    }
}
