<?php

namespace Tests\Feature\Menu;

use App\Enums\DailyMenuAdjustmentAction;
use App\Enums\MenuComponentType;
use App\Enums\WeeklyMenuSection;
use App\Models\Company;
use App\Models\DailyMenuComponentAdjustment;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantMenuAdminBaselineSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuAdminReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_menu_reads_require_menu_manage_permission(): void
    {
        $this->seedAdminMenu();

        $plainUser = User::factory()->create(['company_id' => $this->company()->id]);

        $this->getJson('/api/app/menu/admin/products')->assertUnauthorized();

        $this->actingAs($plainUser)
            ->getJson('/api/app/menu/admin/products')
            ->assertForbidden();
    }

    public function test_admin_products_include_inactive_products_service_days_and_do_not_mutate_data(): void
    {
        $this->seedAdminMenu();
        $this->createOtherCompanyFixtures();
        $before = $this->tableCounts();

        $data = $this->actingAs($this->adminUser())
            ->getJson('/api/app/menu/admin/products?date=2026-07-23')
            ->assertOk()
            ->json('data');

        $products = collect($data['categories'])
            ->flatMap(fn (array $category) => $category['products'])
            ->keyBy('slug');

        $this->assertTrue($products->has('feijoada'));
        $this->assertFalse($products['feijoada']['is_active']);
        $this->assertSame([], $products['feijoada']['service_days']);
        $this->assertSame(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            $products['coca-cola-2l']['service_days'],
        );
        $this->assertSame(['saturday'], $products['feijoada-250ml']['service_days']);
        $this->assertFalse($products->has('produto-externo'));
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_admin_components_include_global_components_and_respect_company_isolation(): void
    {
        $this->seedAdminMenu();
        $this->createOtherCompanyFixtures();
        $before = $this->tableCounts();

        $data = $this->actingAs($this->adminUser())
            ->getJson('/api/app/menu/admin/components')
            ->assertOk()
            ->json('data.components');

        $components = collect($data)->keyBy('slug');

        $this->assertTrue($components->has('bisteca-de-porco-na-chapa'));
        $this->assertSame(MenuComponentType::Meat->value, $components['bisteca-de-porco-na-chapa']['component_type']);
        $this->assertSame('Filé de peixe empanado', $components['file-de-peixe']['name']);
        $this->assertFalse($components->has('componente-externo'));
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_admin_weekly_read_returns_items_by_day_and_section_without_other_companies(): void
    {
        $this->seedAdminMenu();
        $this->createOtherCompanyFixtures();
        $before = $this->tableCounts();

        $data = $this->actingAs($this->adminUser())
            ->getJson('/api/app/menu/admin/weekly')
            ->assertOk()
            ->json('data');

        $mondayMeats = collect($data['days']['monday']['meat'])->pluck('component.slug')->all();
        $tuesdayMeats = collect($data['days']['tuesday']['meat'])->pluck('component.slug')->all();
        $wednesdayMeats = collect($data['days']['wednesday']['meat'])->pluck('component.slug')->all();
        $saturdayMeats = collect($data['days']['saturday']['meat'])->pluck('component.slug')->all();

        $this->assertContains('porco', $mondayMeats);
        $this->assertContains('porco', $saturdayMeats);
        $this->assertContains('churrasco', $mondayMeats);
        $this->assertContains('churrasco', $tuesdayMeats);
        $this->assertNotContains('churrasco', $wednesdayMeats);
        $this->assertContains('file-de-peixe', $wednesdayMeats);
        $this->assertNotContains('bisteca-de-porco-na-chapa', $mondayMeats);
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_admin_day_adjustments_returns_adjustments_for_date_without_mutating_data(): void
    {
        $this->seedAdminMenu();
        $company = $this->company();
        $component = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', 'bisteca-de-porco-na-chapa')
            ->firstOrFail();

        DailyMenuComponentAdjustment::query()->create([
            'company_id' => $company->id,
            'availability_date' => '2026-07-23',
            'menu_component_id' => $component->id,
            'section' => WeeklyMenuSection::Meat,
            'action' => DailyMenuAdjustmentAction::Include,
            'display_order' => 88,
            'notes' => 'Teste local',
        ]);
        $before = $this->tableCounts();

        $data = $this->actingAs($this->adminUser())
            ->getJson('/api/app/menu/admin/day-adjustments?date=2026-07-23')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-07-23', $data['date']);
        $this->assertCount(1, $data['adjustments']);
        $this->assertSame('include', $data['adjustments'][0]['action']);
        $this->assertSame('meat', $data['adjustments'][0]['section']);
        $this->assertSame('bisteca-de-porco-na-chapa', $data['adjustments'][0]['component']['slug']);
        $this->assertSame($before, $this->tableCounts());
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

    private function createOtherCompanyFixtures(): void
    {
        $company = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $category = ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Bebidas',
            'slug' => 'bebidas',
            'category_type' => ProductCategory::TYPE_BEBIDAS,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Produto externo',
            'slug' => 'produto-externo',
            'product_type' => Product::TYPE_BEVERAGE,
            'base_price_cents' => 100,
            'currency' => 'BRL',
        ]);

        MenuComponent::query()->create([
            'company_id' => $company->id,
            'name' => 'Componente externo',
            'slug' => 'componente-externo',
            'component_type' => MenuComponentType::Meat,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'menu_components' => DB::table('menu_components')->count(),
            'weekly_menu_component_items' => DB::table('weekly_menu_component_items')->count(),
            'product_service_days' => DB::table('product_service_days')->count(),
            'daily_menu_component_adjustments' => DB::table('daily_menu_component_adjustments')->count(),
            'daily_component_availability' => DB::table('daily_component_availability')->count(),
        ];
    }
}
