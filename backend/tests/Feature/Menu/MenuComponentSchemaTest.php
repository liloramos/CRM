<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuComponentType;
use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroupComponent;
use App\Models\ProductGroupProduct;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuComponentItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MenuComponentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_component_schema_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('menu_components'));
        $this->assertTrue(Schema::hasTable('product_option_groups'));
        $this->assertTrue(Schema::hasTable('product_group_components'));
        $this->assertTrue(Schema::hasTable('product_group_products'));
        $this->assertTrue(Schema::hasTable('weekly_menu_component_items'));

        $this->assertTrue(Schema::hasColumns('menu_components', [
            'company_id',
            'name',
            'slug',
            'component_type',
            'description',
            'default_price_delta_cents',
            'is_active',
            'display_order',
        ]));

        $this->assertTrue(Schema::hasColumns('product_option_groups', [
            'company_id',
            'product_id',
            'code',
            'label',
            'selection_mode',
            'selection_actor',
            'is_required',
            'min_choices',
            'max_choices',
            'min_quantity',
            'max_quantity',
            'same_component_only',
            'included_in_base_price',
            'display_order',
        ]));

        $this->assertTrue(Schema::hasColumns('product_group_components', [
            'product_option_group_id',
            'menu_component_id',
            'price_delta_cents',
            'final_price_cents',
            'included_quantity',
            'is_default',
            'is_active',
            'requires_confirmation',
            'display_order',
        ]));

        $this->assertTrue(Schema::hasColumns('product_group_products', [
            'product_option_group_id',
            'selectable_product_id',
            'price_delta_cents',
            'final_price_cents',
            'included_quantity',
            'is_default',
            'is_active',
            'requires_confirmation',
            'display_order',
        ]));

        $this->assertTrue(Schema::hasColumns('weekly_menu_component_items', [
            'company_id',
            'weekly_menu_id',
            'service_day',
            'section',
            'menu_component_id',
            'is_active',
            'display_order',
            'notes',
        ]));
    }

    public function test_legacy_menu_tables_continue_to_exist(): void
    {
        foreach ([
            'products',
            'product_categories',
            'product_options',
            'weekly_menus',
            'weekly_menu_items',
            'daily_menu_overrides',
            'daily_menu_option_overrides',
            'orders',
            'order_items',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} should still exist.");
        }
    }

    public function test_menu_component_slug_is_unique_per_company(): void
    {
        $company = $this->createCompany();

        MenuComponent::query()->create([
            'company_id' => $company->id,
            'name' => 'Beterraba',
            'slug' => 'beterraba',
            'component_type' => MenuComponentType::Salad,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        MenuComponent::query()->create([
            'company_id' => $company->id,
            'name' => 'Beterraba duplicada',
            'slug' => 'beterraba',
            'component_type' => MenuComponentType::Salad,
        ]);
    }

    public function test_product_option_group_code_is_unique_per_product(): void
    {
        [$company, $product] = $this->createCompanyAndProduct();

        $this->createOptionGroup($company, $product, 'carne');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createOptionGroup($company, $product, 'carne');
    }

    public function test_product_group_component_links_are_unique(): void
    {
        [$company, $product] = $this->createCompanyAndProduct();
        $group = $this->createOptionGroup($company, $product, 'salada');
        $component = $this->createComponent($company, 'Cenoura', 'cenoura', MenuComponentType::Salad);

        ProductGroupComponent::query()->create([
            'product_option_group_id' => $group->id,
            'menu_component_id' => $component->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ProductGroupComponent::query()->create([
            'product_option_group_id' => $group->id,
            'menu_component_id' => $component->id,
        ]);
    }

    public function test_product_group_product_links_are_unique(): void
    {
        [$company, $product] = $this->createCompanyAndProduct();
        $selectableProduct = $this->createProduct($company, 'Coca-Cola lata', 'coca-cola-lata', Product::TYPE_BEVERAGE);
        $group = $this->createOptionGroup($company, $product, 'bebida_combo');

        ProductGroupProduct::query()->create([
            'product_option_group_id' => $group->id,
            'selectable_product_id' => $selectableProduct->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ProductGroupProduct::query()->create([
            'product_option_group_id' => $group->id,
            'selectable_product_id' => $selectableProduct->id,
        ]);
    }

    public function test_weekly_menu_component_items_are_unique_per_menu_day_section_and_component(): void
    {
        $company = $this->createCompany();
        $weeklyMenu = $this->createWeeklyMenu($company);
        $component = $this->createComponent($company, 'Almondenga', 'almondenga', MenuComponentType::Meat);

        WeeklyMenuComponentItem::query()->create([
            'company_id' => $company->id,
            'weekly_menu_id' => $weeklyMenu->id,
            'service_day' => WeeklyMenuServiceDay::Monday,
            'section' => WeeklyMenuSection::Meat,
            'menu_component_id' => $component->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        WeeklyMenuComponentItem::query()->create([
            'company_id' => $company->id,
            'weekly_menu_id' => $weeklyMenu->id,
            'service_day' => WeeklyMenuServiceDay::Monday,
            'section' => WeeklyMenuSection::Meat,
            'menu_component_id' => $component->id,
        ]);
    }

    public function test_enum_casts_and_relationships_support_component_and_product_choices(): void
    {
        [$company, $product] = $this->createCompanyAndProduct();
        $selectableProduct = $this->createProduct($company, 'Guarana Mineiro Baby', 'guarana-mineiro-baby', Product::TYPE_BEVERAGE);
        $weeklyMenu = $this->createWeeklyMenu($company);
        $component = $this->createComponent($company, 'Frango ao molho', 'frango-ao-molho', MenuComponentType::Meat);
        $group = $this->createOptionGroup($company, $product, 'carne', [
            'label' => 'Carne',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'same_component_only' => true,
        ]);

        $componentLink = ProductGroupComponent::query()->create([
            'product_option_group_id' => $group->id,
            'menu_component_id' => $component->id,
            'included_quantity' => 2,
            'is_default' => true,
        ]);

        $productLink = ProductGroupProduct::query()->create([
            'product_option_group_id' => $group->id,
            'selectable_product_id' => $selectableProduct->id,
            'included_quantity' => 1,
        ]);

        $weeklyItem = WeeklyMenuComponentItem::query()->create([
            'company_id' => $company->id,
            'weekly_menu_id' => $weeklyMenu->id,
            'service_day' => WeeklyMenuServiceDay::Saturday,
            'section' => WeeklyMenuSection::Meat,
            'menu_component_id' => $component->id,
        ]);

        $this->assertSame(MenuComponentType::Meat, $component->refresh()->component_type);
        $this->assertSame(ProductSelectionMode::Single, $group->refresh()->selection_mode);
        $this->assertSame(ProductSelectionActor::Customer, $group->selection_actor);
        $this->assertSame(WeeklyMenuServiceDay::Saturday, $weeklyItem->refresh()->service_day);
        $this->assertSame(WeeklyMenuSection::Meat, $weeklyItem->section);

        $this->assertTrue($company->menuComponents()->first()->is($component));
        $this->assertTrue($company->productOptionGroups()->first()->is($group));
        $this->assertTrue($company->weeklyMenuComponentItems()->first()->is($weeklyItem));
        $this->assertTrue($product->optionGroups()->first()->is($group));
        $this->assertTrue($weeklyMenu->componentItems()->first()->is($weeklyItem));
        $this->assertTrue($group->product()->first()->is($product));
        $this->assertTrue($group->company()->first()->is($company));
        $this->assertTrue($group->componentOptions()->first()->is($componentLink));
        $this->assertTrue($group->productOptions()->first()->is($productLink));
        $this->assertTrue($component->productGroupLinks()->first()->is($componentLink));
        $this->assertTrue($component->weeklyMenuItems()->first()->is($weeklyItem));
        $this->assertTrue($componentLink->group()->first()->is($group));
        $this->assertTrue($componentLink->component()->first()->is($component));
        $this->assertTrue($productLink->group()->first()->is($group));
        $this->assertTrue($productLink->selectableProduct()->first()->is($selectableProduct));
        $this->assertTrue($weeklyItem->company()->first()->is($company));
        $this->assertTrue($weeklyItem->weeklyMenu()->first()->is($weeklyMenu));
        $this->assertTrue($weeklyItem->component()->first()->is($component));
    }

    public function test_schema_does_not_require_operational_seeders_or_remove_legacy_records(): void
    {
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('product_options')->count());
        $this->assertSame(0, DB::table('menu_components')->count());
        $this->assertSame(0, DB::table('product_option_groups')->count());
        $this->assertSame(0, DB::table('product_group_components')->count());
        $this->assertSame(0, DB::table('product_group_products')->count());

        [$company, $product] = $this->createCompanyAndProduct();

        $legacyOption = ProductOption::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'name' => 'Ovo frito',
            'slug' => 'ovo-frito',
            'option_type' => ProductOption::TYPE_ADDON,
            'price_delta_cents' => 200,
        ]);

        $before = [
            'products' => DB::table('products')->count(),
            'product_options' => DB::table('product_options')->count(),
            'weekly_menu_items' => DB::table('weekly_menu_items')->count(),
        ];

        $component = $this->createComponent($company, 'Arroz', 'arroz', MenuComponentType::Base);
        $group = $this->createOptionGroup($company, $product, 'bases_fixas', [
            'selection_mode' => ProductSelectionMode::Fixed,
            'selection_actor' => ProductSelectionActor::System,
        ]);

        ProductGroupComponent::query()->create([
            'product_option_group_id' => $group->id,
            'menu_component_id' => $component->id,
        ]);

        $this->assertDatabaseHas('product_options', ['id' => $legacyOption->id]);
        $this->assertSame($before['products'], DB::table('products')->count());
        $this->assertSame($before['product_options'], DB::table('product_options')->count());
        $this->assertSame($before['weekly_menu_items'], DB::table('weekly_menu_items')->count());
        $this->assertSame(1, DB::table('menu_components')->count());
        $this->assertSame(1, DB::table('product_option_groups')->count());
        $this->assertSame(1, DB::table('product_group_components')->count());
        $this->assertSame(0, DB::table('product_group_products')->count());
    }

    public function test_weekly_menu_service_day_does_not_include_sunday(): void
    {
        $this->assertNull(WeeklyMenuServiceDay::tryFrom('sunday'));
        $this->assertSame(WeeklyMenuServiceDay::Monday, WeeklyMenuServiceDay::tryFrom('monday'));
        $this->assertSame(WeeklyMenuServiceDay::Saturday, WeeklyMenuServiceDay::tryFrom('saturday'));
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Sol Restaurante',
            'slug' => 'restaurante-sol',
        ]);
    }

    /**
     * @return array{0: Company, 1: Product}
     */
    private function createCompanyAndProduct(): array
    {
        $company = $this->createCompany();

        return [$company, $this->createProduct($company, 'N8 Casa', 'n8-casa', Product::TYPE_MARMITA)];
    }

    private function createProduct(Company $company, string $name, string $slug, string $type): Product
    {
        $category = ProductCategory::query()->firstOrCreate(
            ['company_id' => $company->id, 'slug' => 'marmitas'],
            [
                'name' => 'Marmitas',
                'category_type' => ProductCategory::TYPE_MARMITAS,
            ],
        );

        return Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'product_type' => $type,
            'base_price_cents' => 1300,
            'currency' => 'BRL',
        ]);
    }

    private function createWeeklyMenu(Company $company): WeeklyMenu
    {
        return WeeklyMenu::query()->create([
            'company_id' => $company->id,
            'name' => 'Cardapio semanal',
            'slug' => 'cardapio-semanal',
        ]);
    }

    private function createComponent(
        Company $company,
        string $name,
        string $slug,
        MenuComponentType $type,
    ): MenuComponent {
        return MenuComponent::query()->create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => $slug,
            'component_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOptionGroup(
        Company $company,
        Product $product,
        string $code,
        array $overrides = [],
    ): ProductOptionGroup {
        return ProductOptionGroup::query()->create(array_merge([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'code' => $code,
            'label' => str_replace('_', ' ', $code),
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
        ], $overrides));
    }
}
