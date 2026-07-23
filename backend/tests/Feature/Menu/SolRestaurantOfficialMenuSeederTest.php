<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuComponentType;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\WeeklyMenuComponentItem;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SolRestaurantMenuCategorySeeder;
use Database\Seeders\SolRestaurantMenuComponentSeeder;
use Database\Seeders\SolRestaurantOfficialMenuSeeder;
use Database\Seeders\SolRestaurantProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SolRestaurantOfficialMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_seeders_run_in_sqlite_and_are_idempotent(): void
    {
        $this->seed(SolRestaurantMenuCategorySeeder::class);
        $this->seed(SolRestaurantMenuCategorySeeder::class);
        $this->seed(SolRestaurantProductCatalogSeeder::class);
        $this->seed(SolRestaurantProductCatalogSeeder::class);
        $this->seed(SolRestaurantMenuComponentSeeder::class);
        $this->seed(SolRestaurantMenuComponentSeeder::class);

        $company = $this->solRestaurant();

        $this->assertSame(7, ProductCategory::query()->where('company_id', $company->id)->count());
        $this->assertSame(count($this->officialProducts()), Product::query()->where('company_id', $company->id)->count());
        $this->assertSame(64, MenuComponent::query()->where('company_id', $company->id)->count());
    }

    public function test_official_categories_exist_once(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $company = $this->solRestaurant();

        foreach ($this->officialCategories() as $slug => $row) {
            $this->assertSame(1, ProductCategory::query()
                ->where('company_id', $company->id)
                ->where('slug', $slug)
                ->where('name', $row['name'])
                ->where('category_type', $row['category_type'])
                ->where('display_order', $row['display_order'])
                ->where('is_active', true)
                ->count());
        }
    }

    public function test_marmitas_and_combos_have_official_prices(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertProductsHavePrices([
            'n5-casa' => 800,
            'n8-casa' => 1300,
            'n8-tradicional' => 1600,
            'n9-tradicional' => 1800,
            'combo-n8-casa-baby' => 1500,
            'combo-n8-com-latinha' => 2000,
        ]);
    }

    public function test_feijoadas_acai_suco_and_bebidas_have_official_prices(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertProductsHavePrices($this->officialProducts());
    }

    public function test_all_official_latas_cost_five_reais_and_zero_versions_have_expected_prices(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertProductsHavePrices([
            'guarana-lata' => 500,
            'mineiro-lata' => 500,
            'coca-cola-lata-normal' => 500,
            'coca-cola-zero-lata' => 500,
            'mineiro-lata-zero' => 500,
        ]);

        $this->assertProductsHavePrices([
            'sprite-zero' => 1100,
            'coca-cola-1l-zero' => 1000,
            'coca-cola-zero-lata' => 500,
            'mineiro-lata-zero' => 500,
        ]);
    }

    public function test_juice_flavors_exist_as_menu_components_not_products(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        foreach ($this->juiceFlavors() as $name) {
            $this->assertDatabaseHas('menu_components', [
                'slug' => Str::slug($name),
                'name' => $name,
                'component_type' => MenuComponentType::JuiceFlavor->value,
                'default_price_delta_cents' => 0,
                'is_active' => true,
            ]);

            $this->assertDatabaseMissing('products', [
                'slug' => Str::slug("suco {$name}"),
            ]);
        }
    }

    public function test_weekly_menu_components_from_docx_exist_globally(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        foreach ($this->weeklyMenuComponentNames() as $name) {
            $this->assertDatabaseHas('menu_components', [
                'slug' => Str::slug($name),
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }

    public function test_weekly_extras_bife_variants_and_ovo_frito_are_seeded_as_components(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        foreach (['Chuchu refogado', 'Cenoura refogada', 'Quiabo'] as $name) {
            $this->assertDatabaseHas('menu_components', [
                'slug' => Str::slug($name),
                'component_type' => MenuComponentType::Extra->value,
            ]);
        }

        $this->assertDatabaseHas('menu_components', [
            'slug' => 'bife',
            'name' => 'Bife',
            'component_type' => MenuComponentType::Meat->value,
            'default_price_delta_cents' => 0,
        ]);

        $this->assertDatabaseHas('menu_components', [
            'slug' => 'bife-de-figado',
            'name' => 'Bife de fígado',
            'component_type' => MenuComponentType::Meat->value,
        ]);

        $this->assertSame(2, MenuComponent::query()
            ->whereIn('slug', ['bife', 'bife-de-figado'])
            ->count());

        $this->assertDatabaseHas('menu_components', [
            'slug' => 'ovo-frito',
            'name' => 'Ovo frito',
            'component_type' => MenuComponentType::Meat->value,
            'default_price_delta_cents' => 0,
        ]);
    }

    public function test_no_new_paid_ovo_product_is_created(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertDatabaseMissing('products', ['slug' => 'ovo-frito-adicional']);
        $this->assertFalse(Product::query()->where('name', 'like', '%Ovo frito%')->exists());
    }

    public function test_legacy_product_id_10_is_not_changed_when_present(): void
    {
        $company = Company::query()->create([
            'name' => 'Restaurante Sol',
            'slug' => 'restaurante-sol',
        ]);

        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Feijoadas',
            'slug' => 'feijoadas',
            'category_type' => ProductCategory::TYPE_FEIJOADAS,
            'display_order' => 60,
            'is_active' => true,
        ]);

        DB::table('products')->insert([
            'id' => 10,
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Feijoada',
            'slug' => 'feijoada',
            'product_type' => Product::TYPE_FEIJOADA,
            'menu_rule_code' => 'feijoada',
            'description' => null,
            'base_price_cents' => null,
            'currency' => 'BRL',
            'is_active' => false,
            'is_available_by_default' => false,
            'allows_item_notes' => true,
            'notes_hint' => 'Produto legado.',
            'composition_rules' => null,
            'metadata' => null,
            'display_order' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertDatabaseHas('products', [
            'id' => 10,
            'slug' => 'feijoada',
            'name' => 'Feijoada',
            'base_price_cents' => null,
            'is_active' => false,
            'is_available_by_default' => false,
        ]);

        $this->assertDatabaseHas('products', ['slug' => 'feijoada-250ml', 'base_price_cents' => 800]);
        $this->assertDatabaseHas('products', ['slug' => 'feijoada-n5-500ml', 'base_price_cents' => 1500]);
        $this->assertDatabaseHas('products', ['slug' => 'feijoada-750ml', 'base_price_cents' => 1800]);
        $this->assertDatabaseHas('products', ['slug' => 'feijoada-grande-1100ml', 'base_price_cents' => 2200]);
    }

    public function test_sellable_beverages_are_not_seeded_as_menu_components(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        foreach ($this->beverageSlugs() as $slug) {
            $this->assertDatabaseHas('products', ['slug' => $slug, 'product_type' => Product::TYPE_BEVERAGE]);
            $this->assertDatabaseMissing('menu_components', ['slug' => $slug]);
        }
    }

    public function test_seeders_do_not_populate_choice_groups_weekly_items_or_availability(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertSame(0, DB::table('product_option_groups')->count());
        $this->assertSame(0, DB::table('product_group_components')->count());
        $this->assertSame(0, DB::table('product_group_products')->count());
        $this->assertSame(0, WeeklyMenuComponentItem::query()->count());
        $this->assertSame(0, DB::table('weekly_menu_items')->count());
        $this->assertSame(0, DB::table('daily_menu_overrides')->count());
        $this->assertSame(0, DB::table('daily_menu_option_overrides')->count());
    }

    public function test_new_official_seeders_are_not_registered_in_database_seeder(): void
    {
        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString(SolRestaurantOfficialMenuSeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString(SolRestaurantMenuCategorySeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString(SolRestaurantProductCatalogSeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString(SolRestaurantMenuComponentSeeder::class, $databaseSeeder);

        $this->assertContains(DatabaseSeeder::class, [DatabaseSeeder::class]);
    }

    public function test_legacy_tables_and_product_options_remain_available(): void
    {
        $this->seed(SolRestaurantOfficialMenuSeeder::class);

        $this->assertTrue(Schema::hasTable('product_options'));
        $this->assertTrue(Schema::hasTable('weekly_menu_items'));
        $this->assertTrue(Schema::hasTable('daily_menu_overrides'));
        $this->assertTrue(Schema::hasTable('daily_menu_option_overrides'));

        $company = $this->solRestaurant();
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();

        ProductOption::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'name' => 'Opção legada',
            'slug' => 'opcao-legada',
            'option_type' => ProductOption::TYPE_CHOICE,
        ]);

        $this->assertDatabaseHas('product_options', [
            'product_id' => $product->id,
            'slug' => 'opcao-legada',
        ]);
    }

    /**
     * @param  array<string, int>  $expected
     */
    private function assertProductsHavePrices(array $expected): void
    {
        $company = $this->solRestaurant();

        foreach ($expected as $slug => $priceCents) {
            $this->assertDatabaseHas('products', [
                'company_id' => $company->id,
                'slug' => $slug,
                'base_price_cents' => $priceCents,
                'currency' => 'BRL',
                'is_active' => true,
                'is_available_by_default' => true,
            ]);
        }
    }

    private function solRestaurant(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    /**
     * @return array<string, array{name: string, category_type: string, display_order: int}>
     */
    private function officialCategories(): array
    {
        return [
            'marmitas' => ['name' => 'Marmitas', 'category_type' => ProductCategory::TYPE_MARMITAS, 'display_order' => 10],
            'combos' => ['name' => 'Combos', 'category_type' => ProductCategory::TYPE_COMBOS, 'display_order' => 20],
            'bebidas' => ['name' => 'Bebidas', 'category_type' => ProductCategory::TYPE_BEBIDAS, 'display_order' => 30],
            'sucos' => ['name' => 'Sucos', 'category_type' => ProductCategory::TYPE_SUCOS, 'display_order' => 40],
            'acai' => ['name' => 'Açaí', 'category_type' => ProductCategory::TYPE_ACAI, 'display_order' => 50],
            'feijoadas' => ['name' => 'Feijoadas', 'category_type' => ProductCategory::TYPE_FEIJOADAS, 'display_order' => 60],
            'adicionais' => ['name' => 'Adicionais', 'category_type' => ProductCategory::TYPE_ADICIONAIS, 'display_order' => 70],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function officialProducts(): array
    {
        return [
            'n5-casa' => 800,
            'n8-casa' => 1300,
            'n8-tradicional' => 1600,
            'n9-tradicional' => 1800,
            'combo-n8-casa-baby' => 1500,
            'combo-n8-com-latinha' => 2000,
            'feijoada-250ml' => 800,
            'feijoada-n5-500ml' => 1500,
            'feijoada-750ml' => 1800,
            'feijoada-grande-1100ml' => 2200,
            'acai-500ml' => 1500,
            'suco' => 700,
            'coca-cola-2l' => 1300,
            'sprite-zero' => 1100,
            'coca-cola-1l' => 1000,
            'coca-cola-1l-zero' => 1000,
            'guarana-1l' => 800,
            'mineiro-2l' => 1000,
            'h2o-limonetto' => 700,
            'guarana-lata' => 500,
            'guarana-mineiro-baby' => 300,
            'mineiro-lata' => 500,
            'coca-cola-lata-normal' => 500,
            'coca-cola-zero-lata' => 500,
            'mineiro-600ml' => 600,
            'agua-com-gas' => 400,
            'agua-mineral' => 300,
            'coca-cola-600ml' => 800,
            'mineiro-lata-zero' => 500,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function beverageSlugs(): array
    {
        return [
            'coca-cola-2l',
            'sprite-zero',
            'coca-cola-1l',
            'coca-cola-1l-zero',
            'guarana-1l',
            'mineiro-2l',
            'h2o-limonetto',
            'guarana-lata',
            'guarana-mineiro-baby',
            'mineiro-lata',
            'coca-cola-lata-normal',
            'coca-cola-zero-lata',
            'mineiro-600ml',
            'agua-com-gas',
            'agua-mineral',
            'coca-cola-600ml',
            'mineiro-lata-zero',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function juiceFlavors(): array
    {
        return [
            'Goiaba',
            'Tamarindo',
            'Acerola',
            'Abacaxi',
            'Abacaxi com hortelã',
            'Caju',
            'Limão',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function weeklyMenuComponentNames(): array
    {
        return [
            'Arroz branco',
            'Arroz amarelo',
            'Feijão tradicional',
            'Tutu de feijão',
            'Macarrão vermelho',
            'Macarrão alho e óleo',
            'Batata ao molho',
            'Chuchu com cenoura',
            'Repolho alho e óleo',
            'Repolho com tomate',
            'Repolho com maionese',
            'Mandioca',
            'Farofa de cenoura',
            'Jiló',
            'Feijão preto',
            'Purê de batata',
            'Banana frita',
            'Feijão tropeiro',
            'Mix de legumes',
            'Batata frita',
            'Abóbora cabotiá',
            'Abobrinha',
            'Alface',
            'Tomate',
            'Tabule',
            'Batata doce',
            'Maionese',
            'Beterraba',
            'Cenoura',
            'Salada de berinjela',
            'Couve',
            'Vinagrete',
            'Pepino',
            'Salada de macarrão',
            'Salada de cebola',
            'Farofa',
            'Almôndega',
            'Porco',
            'Frango ao molho',
            'Frango frito',
            'Bife de fígado',
            'Ovo frito',
            'Disquinho',
            'Filé de frango empanado',
            'Strogonoff de frango',
            'Filé de peixe',
            'Filé de frango',
            'Strogonoff',
            'Linguiça',
            'Feijoada',
            'Bife',
            'Chuchu refogado',
            'Cenoura refogada',
            'Quiabo',
        ];
    }
}
