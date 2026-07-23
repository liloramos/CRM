<?php

namespace Tests\Feature\Menu;

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroupComponent;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuComponentItem;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SolRestaurantProductRuleSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Database\Seeders\SolRestaurantWeeklyMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SolRestaurantStructuredMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_seeders_run_in_sqlite_and_are_idempotent(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();

        $this->assertSame(7, ProductCategory::query()->where('company_id', $company->id)->count());
        $this->assertSame(29, Product::query()->where('company_id', $company->id)->count());
        $this->assertSame(64, DB::table('menu_components')->where('company_id', $company->id)->count());
        $this->assertSame(10, DB::table('product_option_groups')->where('company_id', $company->id)->count());
        $this->assertSame(30, DB::table('product_group_components')->count());
        $this->assertSame(5, DB::table('product_group_products')->count());
        $this->assertSame(1, WeeklyMenu::query()->where('company_id', $company->id)->count());
        $this->assertSame(210, WeeklyMenuComponentItem::query()->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('product_options')->count());
        $this->assertSame(0, DB::table('daily_menu_overrides')->count());
        $this->assertSame(0, DB::table('daily_menu_option_overrides')->count());
    }

    public function test_n5_casa_has_exact_official_groups_and_components(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame(['bases_fixas', 'salada_casa', 'carne'], $this->groupCodes('n5-casa'));

        $this->assertGroup('n5-casa', 'bases_fixas', [
            'selection_mode' => ProductSelectionMode::Fixed,
            'selection_actor' => ProductSelectionActor::System,
            'is_required' => true,
            'min_choices' => 4,
            'max_choices' => 4,
            'min_quantity' => null,
            'max_quantity' => null,
            'same_component_only' => false,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(['arroz', 'feijao', 'macarrao', 'mandioca'], $this->componentSlugs('n5-casa', 'bases_fixas'));

        $this->assertGroup('n5-casa', 'salada_casa', [
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::House,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => null,
            'max_quantity' => null,
            'same_component_only' => false,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(['beterraba', 'cenoura'], $this->componentSlugs('n5-casa', 'salada_casa'));
        $this->assertNotContains('repolho-com-tomate', $this->componentSlugs('n5-casa', 'salada_casa'));
        $this->assertNotContains('vinagrete', $this->componentSlugs('n5-casa', 'salada_casa'));

        $this->assertGroup('n5-casa', 'carne', [
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => 1,
            'max_quantity' => 1,
            'same_component_only' => true,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(['almondega', 'porco', 'frango-ao-molho'], $this->componentSlugs('n5-casa', 'carne'));
    }

    public function test_n8_casa_has_exact_official_groups_and_components(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame(['bases_fixas', 'salada', 'carne'], $this->groupCodes('n8-casa'));
        $this->assertSame(['arroz', 'feijao', 'macarrao', 'mandioca'], $this->componentSlugs('n8-casa', 'bases_fixas'));

        $this->assertGroup('n8-casa', 'salada', [
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => null,
            'max_quantity' => null,
            'same_component_only' => false,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(
            ['repolho-com-tomate', 'vinagrete', 'beterraba', 'cenoura'],
            $this->componentSlugs('n8-casa', 'salada'),
        );

        $this->assertGroup('n8-casa', 'carne', [
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => 2,
            'max_quantity' => 2,
            'same_component_only' => true,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(
            ['almondega', 'porco', 'frango-ao-molho', 'bife-de-figado'],
            $this->componentSlugs('n8-casa', 'carne'),
        );
    }

    public function test_suco_requires_one_flavor_with_no_price_change(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertGroup('suco', 'sabor', [
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => null,
            'max_quantity' => null,
            'same_component_only' => false,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(
            ['goiaba', 'tamarindo', 'acerola', 'abacaxi', 'abacaxi-com-hortela', 'caju', 'limao'],
            $this->componentSlugs('suco', 'sabor'),
        );

        foreach ($this->componentLinks('suco', 'sabor') as $link) {
            $this->assertSame(0, $link->price_delta_cents);
            $this->assertNull($link->final_price_cents);
            $this->assertTrue($link->is_active);
        }
    }

    public function test_combo_n8_com_latinha_offers_only_real_lata_products(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertGroup('combo-n8-com-latinha', 'bebida_combo', [
            'selection_mode' => ProductSelectionMode::IncludedChoice,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => null,
            'max_quantity' => null,
            'same_component_only' => false,
            'included_in_base_price' => true,
        ]);
        $this->assertSame(
            ['guarana-lata', 'mineiro-lata', 'coca-cola-lata-normal', 'coca-cola-zero-lata', 'mineiro-lata-zero'],
            $this->productSlugs('combo-n8-com-latinha', 'bebida_combo'),
        );
        $this->assertNotContains('latinha', $this->productSlugs('combo-n8-com-latinha', 'bebida_combo'));
        $this->assertNotContains('latinha-zero', $this->productSlugs('combo-n8-com-latinha', 'bebida_combo'));
        $this->assertNotContains('guarana-mineiro-baby', $this->productSlugs('combo-n8-com-latinha', 'bebida_combo'));
    }

    public function test_combo_n8_com_latinha_ignores_legacy_generic_lata_products(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $category = ProductCategory::query()
            ->where('company_id', $company->id)
            ->where('slug', 'bebidas')
            ->firstOrFail();

        foreach (['latinha' => 'Latinha', 'latinha-zero' => 'Latinha Zero'] as $slug => $name) {
            Product::query()->create([
                'company_id' => $company->id,
                'category_id' => $category->id,
                'name' => $name,
                'slug' => $slug,
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => str_replace('-', '_', $slug),
                'base_price_cents' => 500,
                'currency' => 'BRL',
            ]);
        }

        $this->seed(SolRestaurantProductRuleSeeder::class);

        $this->assertSame(
            ['guarana-lata', 'mineiro-lata', 'coca-cola-lata-normal', 'coca-cola-zero-lata', 'mineiro-lata-zero'],
            $this->productSlugs('combo-n8-com-latinha', 'bebida_combo'),
        );
    }

    public function test_combo_baby_does_not_receive_artificial_choice_group(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame([], $this->groupCodes('combo-n8-casa-baby'));
    }

    public function test_bife_variation_rules_are_seeded_for_traditional_marmitas(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame(['variacao_bife'], $this->groupCodes('n8-tradicional'));
        $this->assertSame(['variacao_bife'], $this->groupCodes('n9-tradicional'));

        $n9Link = $this->componentLinks('n9-tradicional', 'variacao_bife')->first();
        $this->assertNotNull($n9Link);
        $this->assertSame(['bife'], $this->componentSlugs('n9-tradicional', 'variacao_bife'));
        $this->assertSame(400, $n9Link->price_delta_cents);
        $this->assertSame(2200, $n9Link->final_price_cents);
        $this->assertFalse($n9Link->requires_confirmation);
        $this->assertTrue($n9Link->is_active);

        $n8Link = $this->componentLinks('n8-tradicional', 'variacao_bife')->first();
        $this->assertNotNull($n8Link);
        $this->assertSame(['bife'], $this->componentSlugs('n8-tradicional', 'variacao_bife'));
        $this->assertSame(0, $n8Link->price_delta_cents);
        $this->assertNull($n8Link->final_price_cents);
        $this->assertTrue($n8Link->requires_confirmation);
        $this->assertFalse($n8Link->is_active);
    }

    public function test_no_paid_ovo_group_or_legacy_ovo_product_link_is_created(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertFalse(ProductOptionGroup::query()->where('code', 'like', '%ovo%')->exists());
        $this->assertFalse(ProductGroupComponent::query()
            ->whereHas('component', fn ($query) => $query->where('slug', 'ovo-frito'))
            ->whereHas('group.product', fn ($query) => $query->whereIn('slug', [
                'n5-casa',
                'n8-casa',
                'n8-tradicional',
                'n9-tradicional',
                'suco',
                'combo-n8-com-latinha',
            ]))
            ->exists());
        $this->assertDatabaseMissing('products', ['slug' => 'ovo-frito-adicional']);
    }

    public function test_monday_weekly_menu_matches_docx(): void
    {
        $this->assertWeeklyDay(WeeklyMenuServiceDay::Monday->value, $this->expectedWeeklyMenu()[WeeklyMenuServiceDay::Monday->value]);
    }

    public function test_tuesday_weekly_menu_matches_docx(): void
    {
        $this->assertWeeklyDay(WeeklyMenuServiceDay::Tuesday->value, $this->expectedWeeklyMenu()[WeeklyMenuServiceDay::Tuesday->value]);
    }

    public function test_wednesday_weekly_menu_matches_docx(): void
    {
        $this->assertWeeklyDay(WeeklyMenuServiceDay::Wednesday->value, $this->expectedWeeklyMenu()[WeeklyMenuServiceDay::Wednesday->value]);
    }

    public function test_thursday_weekly_menu_matches_docx(): void
    {
        $this->assertWeeklyDay(WeeklyMenuServiceDay::Thursday->value, $this->expectedWeeklyMenu()[WeeklyMenuServiceDay::Thursday->value]);
    }

    public function test_friday_weekly_menu_matches_docx(): void
    {
        $this->assertWeeklyDay(WeeklyMenuServiceDay::Friday->value, $this->expectedWeeklyMenu()[WeeklyMenuServiceDay::Friday->value]);
    }

    public function test_saturday_weekly_menu_matches_docx(): void
    {
        $this->assertWeeklyDay(WeeklyMenuServiceDay::Saturday->value, $this->expectedWeeklyMenu()[WeeklyMenuServiceDay::Saturday->value]);
    }

    public function test_weekly_extras_exist_from_monday_to_saturday_and_no_sunday_exists(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        foreach ([
            WeeklyMenuServiceDay::Monday->value,
            WeeklyMenuServiceDay::Tuesday->value,
            WeeklyMenuServiceDay::Wednesday->value,
            WeeklyMenuServiceDay::Thursday->value,
            WeeklyMenuServiceDay::Friday->value,
            WeeklyMenuServiceDay::Saturday->value,
        ] as $day) {
            $this->assertSame(
                ['chuchu-refogado', 'cenoura-refogada', 'quiabo'],
                $this->weeklySectionSlugs($day, WeeklyMenuSection::Extra->value),
            );
        }

        $this->assertSame(0, WeeklyMenuComponentItem::query()->where('service_day', 'sunday')->count());
    }

    public function test_weekly_menu_display_order_is_preserved(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        foreach ($this->expectedWeeklyMenu() as $day => $sections) {
            foreach ($sections as $section => $expectedSlugs) {
                $orders = WeeklyMenuComponentItem::query()
                    ->where('service_day', $day)
                    ->where('section', $section)
                    ->orderBy('display_order')
                    ->pluck('display_order')
                    ->all();

                $this->assertSame(
                    range(10, count($expectedSlugs) * 10, 10),
                    $orders,
                    "{$day}/{$section} should preserve DOCX order.",
                );
            }
        }
    }

    public function test_legacy_product_options_are_not_removed_or_changed(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();

        $option = ProductOption::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'name' => 'Opção legada',
            'slug' => 'opcao-legada',
            'option_type' => ProductOption::TYPE_CHOICE,
            'group_code' => 'legado',
            'price_delta_cents' => 123,
        ]);

        $before = $option->only(['name', 'slug', 'option_type', 'group_code', 'price_delta_cents']);

        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $option->refresh();
        $this->assertSame($before, $option->only(['name', 'slug', 'option_type', 'group_code', 'price_delta_cents']));
    }

    public function test_no_unexpected_products_are_created_by_structured_rules(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame(
            $this->officialProductSlugs(),
            Product::query()->orderBy('display_order')->pluck('slug')->all(),
        );
    }

    public function test_new_structured_seeders_are_not_registered_in_database_seeder(): void
    {
        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString(SolRestaurantProductRuleSeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString(SolRestaurantWeeklyMenuSeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString(SolRestaurantStructuredMenuSeeder::class, $databaseSeeder);
        $this->assertContains(DatabaseSeeder::class, [DatabaseSeeder::class]);
    }

    public function test_no_daily_availability_or_frontend_endpoint_side_effect_is_introduced(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame(0, DB::table('daily_menu_overrides')->count());
        $this->assertSame(0, DB::table('daily_menu_option_overrides')->count());
        $this->assertTrue(Schema::hasTable('weekly_menu_items'));
        $this->assertSame(0, DB::table('weekly_menu_items')->count());
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertGroup(string $productSlug, string $code, array $expected): void
    {
        $group = $this->group($productSlug, $code);

        foreach ($expected as $field => $value) {
            $this->assertSame($value, $group->{$field}, "{$productSlug}/{$code}/{$field}");
        }
    }

    /**
     * @param  array<string, array<int, string>>  $expectedSections
     */
    private function assertWeeklyDay(string $day, array $expectedSections): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        foreach ($expectedSections as $section => $expectedSlugs) {
            $this->assertSame($expectedSlugs, $this->weeklySectionSlugs($day, $section), "{$day}/{$section}");
        }
    }

    /**
     * @return array<int, string>
     */
    private function groupCodes(string $productSlug): array
    {
        return ProductOptionGroup::query()
            ->whereBelongsTo($this->product($productSlug))
            ->orderBy('display_order')
            ->pluck('code')
            ->all();
    }

    private function group(string $productSlug, string $code): ProductOptionGroup
    {
        return ProductOptionGroup::query()
            ->whereBelongsTo($this->product($productSlug))
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function componentSlugs(string $productSlug, string $code): array
    {
        return $this->componentLinks($productSlug, $code)
            ->map(fn (ProductGroupComponent $link): string => $link->component->slug)
            ->all();
    }

    /**
     * @return Collection<int, ProductGroupComponent>
     */
    private function componentLinks(string $productSlug, string $code)
    {
        return $this->group($productSlug, $code)
            ->componentOptions()
            ->with('component')
            ->orderBy('display_order')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function productSlugs(string $productSlug, string $code): array
    {
        return $this->group($productSlug, $code)
            ->productOptions()
            ->with('selectableProduct')
            ->orderBy('display_order')
            ->get()
            ->map(fn ($link): string => $link->selectableProduct->slug)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function weeklySectionSlugs(string $day, string $section): array
    {
        return WeeklyMenuComponentItem::query()
            ->whereBelongsTo($this->weeklyMenu())
            ->where('service_day', $day)
            ->where('section', $section)
            ->with('component')
            ->orderBy('display_order')
            ->get()
            ->map(fn (WeeklyMenuComponentItem $item): string => $item->component->slug)
            ->all();
    }

    private function solRestaurant(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function product(string $slug): Product
    {
        return Product::query()
            ->where('company_id', $this->solRestaurant()->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function weeklyMenu(): WeeklyMenu
    {
        return WeeklyMenu::query()
            ->where('company_id', $this->solRestaurant()->id)
            ->where('slug', 'cardapio-semanal-oficial')
            ->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function officialProductSlugs(): array
    {
        return [
            'n5-casa',
            'n8-casa',
            'n8-tradicional',
            'n9-tradicional',
            'combo-n8-casa-baby',
            'combo-n8-com-latinha',
            'feijoada-250ml',
            'feijoada-n5-500ml',
            'feijoada-750ml',
            'feijoada-grande-1100ml',
            'acai-500ml',
            'suco',
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
     * @return array<string, array<string, array<int, string>>>
     */
    private function expectedWeeklyMenu(): array
    {
        $extras = ['chuchu-refogado', 'cenoura-refogada', 'quiabo'];

        return [
            WeeklyMenuServiceDay::Monday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'tutu-de-feijao',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'batata-ao-molho',
                    'chuchu-com-cenoura',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'salada-de-cebola',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'frango-frito',
                    'bife-de-figado',
                    'ovo-frito',
                    'disquinho',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Tuesday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'tutu-de-feijao',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'farofa-de-cenoura',
                    'jilo',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-frango-empanado',
                    'strogonoff-de-frango',
                    'ovo-frito',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Wednesday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'feijao-preto',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'pure-de-batata',
                    'jilo',
                    'banana-frita',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'farofa',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-peixe',
                    'frango-frito',
                    'ovo-frito',
                    'bife-de-figado',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Thursday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tropeiro',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'mix-de-legumes',
                    'banana-frita',
                    'batata-frita',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'farofa',
                    'salada-de-macarrao',
                    'abobrinha',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-frango',
                    'strogonoff',
                    'ovo-frito',
                    'linguica',
                    'bife-de-figado',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Friday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'tutu-de-feijao',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'farofa-de-cenoura',
                    'abobora-cabotia',
                    'banana-frita',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'farofa',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-frango-empanado',
                    'ovo-frito',
                    'linguica',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Saturday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'pure-de-batata',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'jilo',
                    'abobrinha',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'farofa',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'feijoada',
                    'file-de-frango',
                    'ovo-frito',
                    'linguica',
                    'bife-de-figado',
                    'bife',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
        ];
    }
}
