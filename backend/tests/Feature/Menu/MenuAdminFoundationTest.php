<?php

namespace Tests\Feature\Menu;

use App\Enums\DailyMenuAdjustmentAction;
use App\Enums\MenuComponentType;
use App\Enums\ProductServiceDay;
use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\DailyMenuOverride;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductServiceDay as ProductServiceDayModel;
use App\Models\Role;
use App\Models\User;
use App\Models\WeeklyMenuComponentItem;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantMenuAdminBaselineSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuAdminFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_update_requires_permission_and_company_isolation(): void
    {
        $this->seedAdminMenu();

        $product = $this->product('n5-casa');
        $plainUser = User::factory()->create(['company_id' => $this->company()->id]);
        $admin = $this->adminUser();
        $payload = $this->productPayload($product, priceCents: 900);

        $this->patchJson($this->productUrl($product), $payload)->assertUnauthorized();

        $this->actingAs($plainUser)
            ->patchJson($this->productUrl($product), $payload)
            ->assertForbidden();

        $otherProduct = $this->otherCompanyProduct();

        $this->actingAs($admin)
            ->patchJson($this->productUrl($otherProduct), $this->productPayload($otherProduct))
            ->assertNotFound();
    }

    public function test_product_price_service_days_and_activation_are_managed_without_duplicates(): void
    {
        $this->seedAdminMenu();

        $admin = $this->adminUser();
        $product = $this->product('n5-casa');
        $payload = $this->productPayload(
            $product,
            priceCents: 975,
            serviceDays: [ProductServiceDay::Thursday->value, ProductServiceDay::Saturday->value],
        );

        $this->actingAs($admin)
            ->patchJson($this->productUrl($product), $payload)
            ->assertOk()
            ->assertJsonPath('data.base_price_cents', 975)
            ->assertJsonPath('data.service_days', [ProductServiceDay::Thursday->value, ProductServiceDay::Saturday->value]);

        $this->actingAs($admin)->patchJson($this->productUrl($product), $payload)->assertOk();

        $this->assertSame(7, ProductServiceDayModel::query()->where('product_id', $product->id)->count());
        $this->assertSame(2, ProductServiceDayModel::query()->where('product_id', $product->id)->where('is_active', true)->count());

        $catalogProduct = $this->productFromDayCatalog('2026-07-23', 'n5-casa');
        $this->assertSame(975, $catalogProduct['base_price_cents']);

        $configuration = $this->actingAs($admin)
            ->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23")
            ->assertOk()
            ->json('data');
        $this->assertSame(975, $configuration['base_price_cents']);

        $this->actingAs($admin)
            ->patchJson($this->productUrl($product), [
                ...$payload,
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertFalse($this->catalogHasProduct('2026-07-23', 'n5-casa'));
    }

    public function test_product_schedule_filters_feijoadas_and_legacy_confirmed_products_by_day(): void
    {
        $this->seedAdminMenu();

        foreach (['feijoada-250ml', 'feijoada-n5-500ml', 'feijoada-750ml', 'feijoada-grande-1100ml'] as $slug) {
            $this->assertFalse($this->catalogHasProduct('2026-07-23', $slug));
            $this->assertTrue($this->catalogHasProduct('2026-07-25', $slug));
        }

        foreach (['latinha', 'latinha-zero', 'ovo-frito-adicional'] as $slug) {
            $this->assertTrue($this->product($slug)->is_active);
            $this->assertTrue($this->catalogHasProduct('2026-07-23', $slug));
            $this->assertFalse($this->catalogHasProduct('2026-07-26', $slug));
        }

        $this->assertSame([], $this->day('2026-07-26')['catalog']['categories']);
    }

    public function test_daily_override_is_final_for_active_products_but_does_not_bypass_inactive_products(): void
    {
        $this->seedAdminMenu();

        $company = $this->company();
        $feijoada = $this->product('feijoada-250ml');
        DailyMenuOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $feijoada->id,
            'availability_date' => '2026-07-23',
            'status' => DailyMenuOverride::STATUS_AVAILABLE,
        ]);

        $this->assertTrue($this->catalogHasProduct('2026-07-23', 'feijoada-250ml'));

        $n5 = $this->product('n5-casa');
        DailyMenuOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $n5->id,
            'availability_date' => '2026-07-23',
            'status' => DailyMenuOverride::STATUS_UNAVAILABLE,
        ]);

        $this->assertFalse($this->catalogHasProduct('2026-07-23', 'n5-casa'));

        $n5->update(['is_active' => false]);
        DailyMenuOverride::query()
            ->where('product_id', $n5->id)
            ->update(['status' => DailyMenuOverride::STATUS_AVAILABLE]);

        $this->assertFalse($this->catalogHasProduct('2026-07-23', 'n5-casa'));
    }

    public function test_component_admin_create_update_duplicate_and_cross_company_rules(): void
    {
        $this->seedAdminMenu();

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/app/menu/components', [
                'name' => 'Costela assada',
                'component_type' => MenuComponentType::Meat->value,
                'description' => 'Item eventual.',
                'is_active' => true,
                'display_order' => 740,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'costela-assada');

        $this->actingAs($admin)
            ->postJson('/api/app/menu/components', [
                'name' => 'Costela assada',
                'component_type' => MenuComponentType::Meat->value,
                'is_active' => true,
                'display_order' => 750,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $component = $this->menuComponent('costela-assada');
        $this->actingAs($admin)
            ->patchJson("/api/app/menu/components/{$component->id}", [
                'name' => 'Costela bovina assada',
                'component_type' => MenuComponentType::Meat->value,
                'description' => null,
                'is_active' => false,
                'display_order' => 760,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Costela bovina assada')
            ->assertJsonPath('data.slug', 'costela-assada')
            ->assertJsonPath('data.is_active', false);

        $otherCompanyComponent = MenuComponent::query()->create([
            'company_id' => Company::query()->create(['name' => 'Outra', 'slug' => 'outra'])->id,
            'name' => 'Externo',
            'slug' => 'externo',
            'component_type' => MenuComponentType::Meat,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/components/{$otherCompanyComponent->id}", [
                'name' => 'Externo editado',
                'component_type' => MenuComponentType::Meat->value,
                'is_active' => true,
                'display_order' => 1,
            ])
            ->assertNotFound();
    }

    public function test_confirmed_baseline_items_are_idempotent_and_preserve_legacy_records(): void
    {
        $this->seed([RoleAndPermissionSeeder::class, MenuSeeder::class, SolRestaurantStructuredMenuSeeder::class]);

        $company = $this->company();
        $filePeixe = $this->menuComponent('file-de-peixe');
        $filePeixeId = $filePeixe->id;
        $productOptionsBefore = ProductOption::query()->count();

        $this->seed(SolRestaurantMenuAdminBaselineSeeder::class);
        $this->seed(SolRestaurantMenuAdminBaselineSeeder::class);

        $filePeixe->refresh();
        $this->assertSame($filePeixeId, $filePeixe->id);
        $this->assertSame('Filé de peixe empanado', $filePeixe->name);
        $this->assertSame(1, MenuComponent::query()->where('company_id', $company->id)->where('slug', 'file-de-peixe')->count());
        $this->assertSame(
            [WeeklyMenuServiceDay::Wednesday->value],
            WeeklyMenuComponentItem::query()
                ->where('company_id', $company->id)
                ->where('menu_component_id', $filePeixe->id)
                ->where('is_active', true)
                ->pluck('service_day')
                ->map(fn (WeeklyMenuServiceDay|string $day): string => $day instanceof WeeklyMenuServiceDay ? $day->value : $day)
                ->all(),
        );

        $this->assertSame($productOptionsBefore, ProductOption::query()->count());
        $this->assertTrue($this->product('latinha')->is_active);
        $this->assertTrue($this->product('latinha-zero')->is_active);
        $this->assertSame(200, $this->product('ovo-frito-adicional')->base_price_cents);
        $this->assertTrue($this->product('ovo-frito-adicional')->is_active);
    }

    public function test_churrasco_porco_bisteca_and_combo_rules_match_confirmed_baseline(): void
    {
        $this->seedAdminMenu();

        $this->assertSame(
            [WeeklyMenuServiceDay::Monday->value, WeeklyMenuServiceDay::Tuesday->value],
            $this->weeklyMeatDays('churrasco'),
        );
        $this->assertSame(
            array_column(WeeklyMenuServiceDay::cases(), 'value'),
            $this->weeklyMeatDays('porco'),
        );
        $this->assertSame([], $this->weeklyMeatDays('bisteca-de-porco-na-chapa'));

        $n5 = $this->productConfiguration('n5-casa');
        $n8 = $this->productConfiguration('n8-casa');

        $this->assertNotContains('bisteca-de-porco-na-chapa', $this->componentGroupSlugs($n5, 'carne'));
        $this->assertNotContains('bisteca-de-porco-na-chapa', $this->componentGroupSlugs($n8, 'carne'));
        $this->assertSame(
            ['guarana-lata', 'mineiro-lata', 'coca-cola-lata-normal', 'coca-cola-zero-lata', 'mineiro-lata-zero'],
            $this->productGroupSlugs($this->productConfiguration('combo-n8-com-latinha'), 'bebida_combo'),
        );
    }

    public function test_weekly_menu_admin_adds_updates_and_removes_links_without_touching_other_days(): void
    {
        $this->seedAdminMenu();

        $admin = $this->adminUser();
        $bisteca = $this->menuComponent('bisteca-de-porco-na-chapa');

        $itemId = $this->actingAs($admin)
            ->patchJson("/api/app/menu/weekly/components/{$bisteca->id}", [
                'service_day' => WeeklyMenuServiceDay::Tuesday->value,
                'section' => WeeklyMenuSection::Meat->value,
                'display_order' => 15,
                'is_active' => true,
                'notes' => 'Teste semanal.',
            ])
            ->assertOk()
            ->assertJsonPath('data.component.slug', 'bisteca-de-porco-na-chapa')
            ->json('data.id');

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/weekly-items/{$itemId}", [
                'service_day' => WeeklyMenuServiceDay::Tuesday->value,
                'section' => WeeklyMenuSection::Extra->value,
                'display_order' => 25,
                'is_active' => true,
                'notes' => 'Movido para extras.',
            ])
            ->assertOk()
            ->assertJsonPath('data.section', WeeklyMenuSection::Extra->value);

        $porcoTuesday = $this->weeklyItem('porco', WeeklyMenuServiceDay::Tuesday, WeeklyMenuSection::Meat);
        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/weekly-items/{$porcoTuesday->id}")
            ->assertOk()
            ->assertJsonPath('data.cleared', true);

        $this->assertNull($this->weeklyItemOrNull('porco', WeeklyMenuServiceDay::Tuesday, WeeklyMenuSection::Meat));
        $this->assertNotNull($this->weeklyItemOrNull('porco', WeeklyMenuServiceDay::Wednesday, WeeklyMenuSection::Meat));
    }

    public function test_date_specific_adjustments_include_exclude_clear_and_remain_date_scoped(): void
    {
        $this->seedAdminMenu();

        $admin = $this->adminUser();
        $bisteca = $this->menuComponent('bisteca-de-porco-na-chapa');
        $porco = $this->menuComponent('porco');

        $this->actingAs($admin)
            ->patchJson($this->dailyAdjustmentUrl($bisteca), [
                'date' => '2026-07-23',
                'section' => WeeklyMenuSection::Meat->value,
                'action' => DailyMenuAdjustmentAction::Include->value,
                'display_order' => 5,
                'notes' => 'Carne eventual.',
            ])
            ->assertOk()
            ->assertJsonPath('data.marked_by_user_id', $admin->id);

        $this->actingAs($admin)
            ->patchJson($this->dailyAdjustmentUrl($bisteca), [
                'date' => '2026-07-23',
                'section' => WeeklyMenuSection::Meat->value,
                'action' => DailyMenuAdjustmentAction::Include->value,
                'display_order' => 5,
                'notes' => 'Carne eventual.',
            ])
            ->assertOk();

        $this->assertSame(1, DB::table('daily_menu_component_adjustments')->where('menu_component_id', $bisteca->id)->count());
        $this->assertContains('bisteca-de-porco-na-chapa', $this->sectionSlugs('2026-07-23', WeeklyMenuSection::Meat));
        $this->assertNotContains('bisteca-de-porco-na-chapa', $this->sectionSlugs('2026-07-30', WeeklyMenuSection::Meat));

        $this->actingAs($admin)
            ->patchJson($this->dailyAdjustmentUrl($porco), [
                'date' => '2026-07-23',
                'section' => WeeklyMenuSection::Meat->value,
                'action' => DailyMenuAdjustmentAction::Exclude->value,
            ])
            ->assertOk();

        $this->assertNotContains('porco', $this->sectionSlugs('2026-07-23', WeeklyMenuSection::Meat));
        $this->assertContains('porco', $this->sectionSlugs('2026-07-30', WeeklyMenuSection::Meat));

        $this->actingAs($admin)
            ->deleteJson($this->dailyAdjustmentUrl($porco), [
                'date' => '2026-07-23',
                'section' => WeeklyMenuSection::Meat->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.cleared', true);

        $this->assertContains('porco', $this->sectionSlugs('2026-07-23', WeeklyMenuSection::Meat));

        $this->actingAs($admin)
            ->patchJson($this->dailyAdjustmentUrl($bisteca), [
                'date' => '2026-02-31',
                'section' => WeeklyMenuSection::Meat->value,
                'action' => DailyMenuAdjustmentAction::Include->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_legacy_endpoint_orders_printing_and_frontend_are_preserved(): void
    {
        $this->seedAdminMenu();

        $this->getJson('/api/restaurants/restaurante-sol/menu/available?date=2026-07-23')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'n5-casa']);

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());

        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $this->assertStringNotContainsString(SolRestaurantMenuAdminBaselineSeeder::class, $databaseSeeder);
        $this->assertDirectoryExists(base_path('../frontend/src/features/cardapio'));
    }

    private function seedAdminMenu(): void
    {
        $this->seed([
            RoleAndPermissionSeeder::class,
            MenuSeeder::class,
            SolRestaurantStructuredMenuSeeder::class,
            SolRestaurantMenuAdminBaselineSeeder::class,
        ]);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['company_id' => $this->company()->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return $user;
    }

    private function company(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function product(string $slug): Product
    {
        return Product::query()
            ->where('company_id', $this->company()->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function menuComponent(string $slug): MenuComponent
    {
        return MenuComponent::query()
            ->where('company_id', $this->company()->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function productUrl(Product $product): string
    {
        return "/api/app/menu/products/{$product->id}";
    }

    private function dailyAdjustmentUrl(MenuComponent $component): string
    {
        return "/api/app/menu/day/components/{$component->id}";
    }

    /**
     * @param  array<int, string>  $serviceDays
     * @return array<string, mixed>
     */
    private function productPayload(
        Product $product,
        ?int $priceCents = null,
        array $serviceDays = [
            ProductServiceDay::Monday->value,
            ProductServiceDay::Tuesday->value,
            ProductServiceDay::Wednesday->value,
            ProductServiceDay::Thursday->value,
            ProductServiceDay::Friday->value,
            ProductServiceDay::Saturday->value,
        ],
    ): array {
        return [
            'date' => '2026-07-23',
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $priceCents ?? (int) $product->base_price_cents,
            'is_active' => (bool) $product->is_active,
            'is_available_by_default' => (bool) $product->is_available_by_default,
            'display_order' => (int) $product->display_order,
            'service_days' => $serviceDays,
        ];
    }

    private function otherCompanyProduct(): Product
    {
        $company = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Bebidas',
            'slug' => 'bebidas',
            'category_type' => ProductCategory::TYPE_BEBIDAS,
        ]);

        return Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Produto externo',
            'slug' => 'produto-externo',
            'product_type' => Product::TYPE_BEVERAGE,
            'base_price_cents' => 100,
            'currency' => 'BRL',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function day(string $date): array
    {
        return $this->actingAs($this->adminUser())
            ->getJson("/api/app/menu/day?date={$date}")
            ->assertOk()
            ->json('data');
    }

    private function catalogHasProduct(string $date, string $slug): bool
    {
        foreach ($this->day($date)['catalog']['categories'] as $category) {
            foreach ($category['products'] as $product) {
                if ($product['slug'] === $slug) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function productFromDayCatalog(string $date, string $slug): array
    {
        foreach ($this->day($date)['catalog']['categories'] as $category) {
            foreach ($category['products'] as $product) {
                if ($product['slug'] === $slug) {
                    return $product;
                }
            }
        }

        $this->fail("Produto {$slug} nao encontrado no catalogo.");
    }

    /**
     * @return array<string, mixed>
     */
    private function productConfiguration(string $slug): array
    {
        $product = $this->product($slug);

        return $this->actingAs($this->adminUser())
            ->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23")
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<int, string>
     */
    private function componentGroupSlugs(array $product, string $groupCode): array
    {
        $group = collect($product['groups'])->firstWhere('code', $groupCode);

        return array_column($group['component_options'], 'slug');
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<int, string>
     */
    private function productGroupSlugs(array $product, string $groupCode): array
    {
        $group = collect($product['groups'])->firstWhere('code', $groupCode);

        return array_map(fn (array $option): string => $option['selectable_product']['slug'], $group['product_options']);
    }

    /**
     * @return array<int, string>
     */
    private function weeklyMeatDays(string $componentSlug): array
    {
        return WeeklyMenuComponentItem::query()
            ->where('company_id', $this->company()->id)
            ->where('menu_component_id', $this->menuComponent($componentSlug)->id)
            ->where('section', WeeklyMenuSection::Meat->value)
            ->where('is_active', true)
            ->orderBy('service_day')
            ->pluck('service_day')
            ->map(fn (WeeklyMenuServiceDay|string $day): string => $day instanceof WeeklyMenuServiceDay ? $day->value : $day)
            ->sortBy(fn (string $day): int => match ($day) {
                'monday' => 10,
                'tuesday' => 20,
                'wednesday' => 30,
                'thursday' => 40,
                'friday' => 50,
                'saturday' => 60,
                default => 99,
            })
            ->values()
            ->all();
    }

    private function weeklyItem(string $componentSlug, WeeklyMenuServiceDay $day, WeeklyMenuSection $section): WeeklyMenuComponentItem
    {
        return $this->weeklyItemOrNull($componentSlug, $day, $section) ?? $this->fail('Vinculo semanal esperado nao encontrado.');
    }

    private function weeklyItemOrNull(string $componentSlug, WeeklyMenuServiceDay $day, WeeklyMenuSection $section): ?WeeklyMenuComponentItem
    {
        return WeeklyMenuComponentItem::query()
            ->where('company_id', $this->company()->id)
            ->where('menu_component_id', $this->menuComponent($componentSlug)->id)
            ->where('service_day', $day->value)
            ->where('section', $section->value)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function sectionSlugs(string $date, WeeklyMenuSection $section): array
    {
        return array_map(
            fn (array $item): string => $item['component']['slug'],
            $this->day($date)['sections'][$section->value],
        );
    }
}
