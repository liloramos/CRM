<?php

namespace Tests\Feature\Menu;

use App\Data\Menu\ComponentAvailabilityResult;
use App\Enums\MenuAvailabilityStatus;
use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\DailyComponentAvailability;
use App\Models\DailyProductComponentOverride;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\User;
use App\Models\WeeklyMenu;
use App\Services\Menu\ComponentAvailabilityResolver;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Menu\StructuredMenuCatalogService;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StructuredMenuReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_availability_uses_component_default_without_writing_records(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $component = $this->menuComponent('almondega');
        $resolver = app(ComponentAvailabilityResolver::class);

        $beforeCounts = $this->availabilityCounts();

        $available = $resolver->resolve($company, $component, '2026-07-23');

        $this->assertSame(MenuAvailabilityStatus::Available, $available->status);
        $this->assertTrue($available->available);
        $this->assertSame(ComponentAvailabilityResult::SOURCE_COMPONENT_DEFAULT, $available->source);
        $this->assertNull($available->reason);
        $this->assertNull($available->replacementComponent);
        $this->assertSame('2026-07-23', $available->availabilityDate);
        $this->assertSame($beforeCounts, $this->availabilityCounts());

        $component->update(['is_active' => false]);

        $unavailable = app(ComponentAvailabilityResolver::class)
            ->resolve($company, $component->refresh(), '2026-07-23');

        $this->assertSame(MenuAvailabilityStatus::Unavailable, $unavailable->status);
        $this->assertFalse($unavailable->available);
        $this->assertSame(ComponentAvailabilityResult::SOURCE_COMPONENT_DEFAULT, $unavailable->source);
        $this->assertSame($beforeCounts, $this->availabilityCounts());
    }

    public function test_global_component_availability_status_reason_and_replacement_are_returned(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $component = $this->menuComponent('almondega');
        $replacement = $this->menuComponent('porco');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou durante a operação.',
            'replacement_component_id' => $replacement->id,
        ]);

        $result = app(ComponentAvailabilityResolver::class)->resolve($company, $component, '2026-07-23');

        $this->assertSame(MenuAvailabilityStatus::SoldOut, $result->status);
        $this->assertFalse($result->available);
        $this->assertSame(ComponentAvailabilityResult::SOURCE_GLOBAL_AVAILABILITY, $result->source);
        $this->assertSame('Acabou durante a operação.', $result->reason);
        $this->assertTrue($result->replacementComponent?->is($replacement));
        $this->assertSame('porco', $result->toArray()['replacement']['slug']);
    }

    public function test_product_override_has_precedence_over_global_availability(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('almondega');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou globalmente.',
        ]);

        DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Available,
            'reason' => 'Reservado para N5 Casa.',
        ]);

        $result = app(ComponentAvailabilityResolver::class)->resolve($company, $component, '2026-07-23', $product);

        $this->assertSame(MenuAvailabilityStatus::Available, $result->status);
        $this->assertTrue($result->available);
        $this->assertSame(ComponentAvailabilityResult::SOURCE_PRODUCT_OVERRIDE, $result->source);
        $this->assertSame('Reservado para N5 Casa.', $result->reason);
    }

    public function test_product_configuration_returns_structured_rules_combo_items_and_pending_variations(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $date = CarbonImmutable::create(2026, 7, 23);
        $service = app(StructuredProductConfigurationService::class);

        $n5 = $service->configuration($this->product('n5-casa'), $company, $date);
        $this->assertSame(['bases_fixas', 'salada_casa', 'carne'], array_column($n5['groups'], 'code'));
        $this->assertSame('fixed', $this->group($n5, 'bases_fixas')['selection_mode']);
        $this->assertSame('system', $this->group($n5, 'bases_fixas')['selection_actor']);
        $this->assertSame(['arroz', 'feijao', 'macarrao', 'mandioca'], $this->componentOptionSlugs($n5, 'bases_fixas'));
        $this->assertSame('house', $this->group($n5, 'salada_casa')['selection_actor']);
        $this->assertSame(['beterraba', 'cenoura'], $this->componentOptionSlugs($n5, 'salada_casa'));
        $this->assertSame(['almondega', 'porco', 'frango-ao-molho'], $this->componentOptionSlugs($n5, 'carne'));
        $this->assertSame(1, $this->group($n5, 'carne')['min_quantity']);
        $this->assertSame(1, $this->group($n5, 'carne')['max_quantity']);

        $n8 = $service->configuration($this->product('n8-casa'), $company, $date);
        $this->assertSame(['bases_fixas', 'salada', 'carne'], array_column($n8['groups'], 'code'));
        $this->assertSame(['repolho-com-tomate', 'vinagrete', 'beterraba', 'cenoura'], $this->componentOptionSlugs($n8, 'salada'));
        $this->assertSame(['almondega', 'porco', 'frango-ao-molho', 'bife-de-figado'], $this->componentOptionSlugs($n8, 'carne'));
        $this->assertSame(2, $this->group($n8, 'carne')['min_quantity']);
        $this->assertSame(2, $this->group($n8, 'carne')['max_quantity']);
        $this->assertTrue($this->group($n8, 'carne')['same_component_only']);

        $suco = $service->configuration($this->product('suco'), $company, $date);
        $this->assertSame(
            ['goiaba', 'tamarindo', 'acerola', 'abacaxi', 'abacaxi-com-hortela', 'caju', 'limao'],
            $this->componentOptionSlugs($suco, 'sabor'),
        );
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], array_column($this->group($suco, 'sabor')['component_options'], 'price_delta_cents'));

        $comboLatinha = $service->configuration($this->product('combo-n8-com-latinha'), $company, $date);
        $this->assertSame(
            ['guarana-lata', 'mineiro-lata', 'coca-cola-lata-normal', 'coca-cola-zero-lata', 'mineiro-lata-zero'],
            $this->productOptionSlugs($comboLatinha, 'bebida_combo'),
        );
        $this->assertSame(['n8-tradicional'], array_column(array_column($comboLatinha['combo_items'], 'included_product'), 'slug'));

        $comboBaby = $service->configuration($this->product('combo-n8-casa-baby'), $company, $date);
        $this->assertSame(['n8-casa', 'guarana-mineiro-baby'], array_column(array_column($comboBaby['combo_items'], 'included_product'), 'slug'));
        $this->assertSame([], $comboBaby['groups']);

        $n9 = $service->configuration($this->product('n9-tradicional'), $company, $date);
        $n9Bife = $this->group($n9, 'variacao_bife')['component_options'][0];
        $this->assertSame('bife', $n9Bife['slug']);
        $this->assertSame(400, $n9Bife['price_delta_cents']);
        $this->assertSame(2200, $n9Bife['final_price_cents']);
        $this->assertTrue($n9Bife['link_active']);
        $this->assertFalse($n9Bife['requires_confirmation']);

        $n8Tradicional = $service->configuration($this->product('n8-tradicional'), $company, $date);
        $n8Bife = $this->group($n8Tradicional, 'variacao_bife')['component_options'][0];
        $this->assertSame('bife', $n8Bife['slug']);
        $this->assertNull($n8Bife['final_price_cents']);
        $this->assertFalse($n8Bife['link_active']);
        $this->assertTrue($n8Bife['requires_confirmation']);
        $this->assertTrue($n8Tradicional['configuration_pending']);
    }

    public function test_product_configuration_marks_unavailable_component_options(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('beterraba');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
            'reason' => 'Não oferecer hoje.',
        ]);

        $config = app(StructuredProductConfigurationService::class)
            ->configuration($product, $company, CarbonImmutable::create(2026, 7, 23));
        $beterraba = collect($this->group($config, 'salada_casa')['component_options'])
            ->firstWhere('slug', 'beterraba');

        $this->assertFalse($beterraba['available']);
        $this->assertSame('unavailable', $beterraba['availability']['status']);
        $this->assertSame('Não oferecer hoje.', $beterraba['availability']['reason']);
    }

    public function test_catalog_service_groups_sellable_products_and_avoids_obvious_n_plus_one(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $catalog = app(StructuredMenuCatalogService::class)
            ->catalog($this->solRestaurant(), CarbonImmutable::create(2026, 7, 23));

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $categorySlugs = array_column($catalog['categories'], 'slug');
        $this->assertContains('marmitas', $categorySlugs);
        $this->assertContains('bebidas', $categorySlugs);
        $this->assertContains('combos', $categorySlugs);
        $this->assertLessThan(40, $queryCount);
    }

    public function test_daily_menu_returns_weekly_sections_for_each_service_day_and_empty_sunday(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $service = app(DailyStructuredMenuService::class);
        $company = $this->solRestaurant();

        foreach ($this->expectedWeeklyMenu() as $date => $expectation) {
            $payload = $service->day($company, CarbonImmutable::parse($date));

            $this->assertTrue($payload['is_service_day']);
            $this->assertSame($expectation['service_day'], $payload['service_day']);

            foreach ($expectation['sections'] as $section => $expectedSlugs) {
                $this->assertSame($expectedSlugs, $this->sectionSlugs($payload, $section), "{$date}/{$section}");
            }
        }

        $sunday = $service->day($company, CarbonImmutable::parse('2026-07-26'));

        $this->assertFalse($sunday['is_service_day']);
        $this->assertNull($sunday['service_day']);
        $this->assertNull($sunday['weekly_menu']);
        $this->assertSame([
            'hot' => [],
            'salad' => [],
            'meat' => [],
            'extra' => [],
        ], $sunday['sections']);
        $this->assertSame(['n8-tradicional', 'n9-tradicional'], array_column($sunday['traditional_products'], 'slug'));
    }

    public function test_daily_menu_prefers_structured_weekly_menu_when_legacy_menu_is_also_active(): void
    {
        $company = Company::query()->create([
            'name' => 'Sol Restaurante',
            'slug' => 'restaurante-sol',
        ]);
        WeeklyMenu::query()->create([
            'company_id' => $company->id,
            'name' => 'Cardapio semanal legado',
            'slug' => 'cardapio-semanal-legado',
            'is_active' => true,
        ]);

        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $payload = app(DailyStructuredMenuService::class)
            ->day($company->refresh(), CarbonImmutable::parse('2026-07-23'));

        $this->assertSame('cardapio-semanal-oficial', $payload['weekly_menu']['slug']);
        $this->assertSame(
            [
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
            $this->sectionSlugs($payload, 'hot'),
        );
    }

    public function test_daily_menu_keeps_sold_out_items_with_replacement_without_auto_swap(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $this->menuComponent('almondega')->id,
            'availability_date' => '2026-07-20',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou cedo.',
            'replacement_component_id' => $this->menuComponent('porco')->id,
        ]);

        $payload = app(DailyStructuredMenuService::class)->day($company, CarbonImmutable::parse('2026-07-20'));
        $item = collect($payload['sections']['meat'])->first(fn (array $row): bool => $row['component']['slug'] === 'almondega');

        $this->assertNotNull($item);
        $this->assertFalse($item['available']);
        $this->assertSame('sold_out', $item['availability']['status']);
        $this->assertSame('porco', $item['availability']['replacement']['slug']);
        $this->assertContains('almondega', $this->sectionSlugs($payload, 'meat'));
    }

    public function test_authenticated_endpoints_return_structured_payloads_and_validate_date(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->getJson('/api/app/menu/catalog?date=2026-07-23')->assertUnauthorized();

        $catalog = $this->actingAs($user)->getJson('/api/app/menu/catalog?date=2026-07-23');
        $catalog->assertOk()
            ->assertJsonPath('data.date', '2026-07-23')
            ->assertJsonFragment(['slug' => 'n5-casa'])
            ->assertJsonMissing(['fallback' => true])
            ->assertJsonMissing(['slug' => 'marmita-executiva-demo']);

        $day = $this->actingAs($user)->getJson('/api/app/menu/day?date=2026-07-23');
        $day->assertOk()
            ->assertJsonPath('data.service_day', 'thursday')
            ->assertJsonPath('data.is_service_day', true)
            ->assertJsonPath('data.sections.meat.0.component.slug', 'almondega');

        $sunday = $this->actingAs($user)->getJson('/api/app/menu/day?date=2026-07-26');
        $sunday->assertOk()
            ->assertJsonPath('data.is_service_day', false)
            ->assertJsonPath('data.weekly_menu', null)
            ->assertJsonPath('data.sections.hot', []);

        $product = $this->product('n5-casa');
        $configuration = $this->actingAs($user)->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23");
        $configuration->assertOk()
            ->assertJsonPath('data.slug', 'n5-casa')
            ->assertJsonPath('data.groups.0.code', 'bases_fixas');

        $this->actingAs($user)
            ->getJson('/api/app/menu/day?date=2026-02-31')
            ->assertUnprocessable();
    }

    public function test_product_configuration_endpoint_blocks_cross_company_and_missing_products(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $otherCategory = ProductCategory::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Bebidas',
            'slug' => 'bebidas',
            'category_type' => ProductCategory::TYPE_BEBIDAS,
        ]);
        $otherProduct = Product::query()->create([
            'company_id' => $otherCompany->id,
            'category_id' => $otherCategory->id,
            'name' => 'Produto externo',
            'slug' => 'produto-externo',
            'product_type' => Product::TYPE_BEVERAGE,
            'base_price_cents' => 100,
            'currency' => 'BRL',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)
            ->getJson("/api/app/menu/products/{$otherProduct->id}/configuration?date=2026-07-23")
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/api/app/menu/products/999999/configuration?date=2026-07-23')
            ->assertNotFound();
    }

    public function test_new_endpoints_are_read_only_and_preserve_legacy_tables(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $option = ProductOption::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'name' => 'Opção legada',
            'slug' => 'opcao-legada',
            'option_type' => ProductOption::TYPE_CHOICE,
            'group_code' => 'legado',
            'price_delta_cents' => 123,
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $beforeCounts = $this->readOnlyCounts();

        $this->actingAs($user)->getJson('/api/app/menu/catalog?date=2026-07-23')->assertOk();
        $this->actingAs($user)->getJson('/api/app/menu/day?date=2026-07-23')->assertOk();
        $this->actingAs($user)->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23")->assertOk();

        $this->assertSame($beforeCounts, $this->readOnlyCounts());
        $this->assertDatabaseHas('product_options', ['id' => $option->id, 'price_delta_cents' => 123]);
        $this->assertSame(0, DB::table('daily_component_availability')->count());
        $this->assertSame(0, DB::table('daily_product_component_overrides')->count());
    }

    public function test_legacy_available_menu_endpoint_keeps_legacy_payload_and_behavior(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $response = $this->getJson('/api/restaurants/restaurante-sol/menu/available?date=2026-07-06');

        $response->assertOk()
            ->assertJsonFragment(['slug' => 'n5-casa'])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'company_id',
                        'category_id',
                        'name',
                        'slug',
                        'composition_rules',
                        'options',
                    ],
                ],
            ]);
    }

    public function test_structured_menu_routes_are_not_registered_in_legacy_api_file_or_database_seeder(): void
    {
        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $apiRoutes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringNotContainsString('StructuredMenuCatalogController', $apiRoutes);
        $this->assertStringNotContainsString('DailyStructuredMenuController', $apiRoutes);
        $this->assertStringNotContainsString(SolRestaurantStructuredMenuSeeder::class, $databaseSeeder);
    }

    /**
     * @return array<string, int>
     */
    private function availabilityCounts(): array
    {
        return [
            'daily_component_availability' => DB::table('daily_component_availability')->count(),
            'daily_product_component_overrides' => DB::table('daily_product_component_overrides')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function readOnlyCounts(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'product_options' => DB::table('product_options')->count(),
            'product_option_groups' => DB::table('product_option_groups')->count(),
            'product_group_components' => DB::table('product_group_components')->count(),
            'product_group_products' => DB::table('product_group_products')->count(),
            'weekly_menu_component_items' => DB::table('weekly_menu_component_items')->count(),
            'combo_items' => DB::table('combo_items')->count(),
            'daily_component_availability' => DB::table('daily_component_availability')->count(),
            'daily_product_component_overrides' => DB::table('daily_product_component_overrides')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function group(array $product, string $code): array
    {
        $group = collect($product['groups'])->firstWhere('code', $code);

        $this->assertIsArray($group, "Group {$code} should exist.");

        return $group;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<int, string>
     */
    private function componentOptionSlugs(array $product, string $groupCode): array
    {
        return array_column($this->group($product, $groupCode)['component_options'], 'slug');
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<int, string>
     */
    private function productOptionSlugs(array $product, string $groupCode): array
    {
        return array_map(
            fn (array $option): string => $option['selectable_product']['slug'],
            $this->group($product, $groupCode)['product_options'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function sectionSlugs(array $payload, string $section): array
    {
        return array_map(
            fn (array $item): string => $item['component']['slug'],
            $payload['sections'][$section],
        );
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

    private function menuComponent(string $slug): MenuComponent
    {
        return MenuComponent::query()
            ->where('company_id', $this->solRestaurant()->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * @return array<string, array{service_day: string, sections: array<string, array<int, string>>}>
     */
    private function expectedWeeklyMenu(): array
    {
        $extras = ['chuchu-refogado', 'cenoura-refogada', 'quiabo'];

        return [
            '2026-07-20' => [
                'service_day' => WeeklyMenuServiceDay::Monday->value,
                'sections' => [
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
            ],
            '2026-07-21' => [
                'service_day' => WeeklyMenuServiceDay::Tuesday->value,
                'sections' => [
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
            ],
            '2026-07-22' => [
                'service_day' => WeeklyMenuServiceDay::Wednesday->value,
                'sections' => [
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
            ],
            '2026-07-23' => [
                'service_day' => WeeklyMenuServiceDay::Thursday->value,
                'sections' => [
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
            ],
            '2026-07-24' => [
                'service_day' => WeeklyMenuServiceDay::Friday->value,
                'sections' => [
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
            ],
            '2026-07-25' => [
                'service_day' => WeeklyMenuServiceDay::Saturday->value,
                'sections' => [
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
            ],
        ];
    }
}
