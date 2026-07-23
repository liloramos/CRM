<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuAvailabilityStatus;
use App\Models\Company;
use App\Models\DailyComponentAvailability;
use App\Models\DailyProductComponentOverride;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\Role;
use App\Models\User;
use App\Models\WeeklyMenuComponentItem;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuComponentAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_endpoints_require_authentication_and_menu_manage_permission(): void
    {
        $this->seedStructuredMenu();

        $component = $this->menuComponent('almondega');
        $product = $this->product('n5-casa');
        $plainUser = User::factory()->create(['company_id' => $this->solRestaurant()->id]);
        $admin = $this->adminUser();

        $payload = [
            'date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut->value,
        ];

        $this->patchJson($this->globalUrl($component), $payload)->assertUnauthorized();

        $this->actingAs($plainUser)
            ->patchJson($this->globalUrl($component), $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($component), $payload)
            ->assertOk()
            ->assertJsonPath('data.scope', 'global');

        $this->actingAs($plainUser)
            ->patchJson($this->productUrl($product, $component), $payload)
            ->assertForbidden();
    }

    public function test_cross_company_component_product_and_replacement_are_blocked(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
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
        $otherComponent = MenuComponent::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Componente externo',
            'slug' => 'componente-externo',
            'component_type' => 'meat',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($otherComponent), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patchJson($this->productUrl($otherProduct, $this->menuComponent('almondega')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Unavailable->value,
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($this->menuComponent('almondega')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
                'replacement_component_id' => $otherComponent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('replacement_component_id');

        $this->assertSame(0, DailyComponentAvailability::query()->count());
        $this->assertSame(0, DailyProductComponentOverride::query()->count());
    }

    public function test_validation_rejects_invalid_payloads_without_creating_records(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $component = $this->menuComponent('almondega');
        $inactiveReplacement = MenuComponent::query()->create([
            'company_id' => $this->solRestaurant()->id,
            'name' => 'Substituto inativo',
            'slug' => 'substituto-inativo',
            'component_type' => 'meat',
            'is_active' => false,
        ]);

        $invalidPayloads = [
            [['date' => '2026-02-31', 'status' => 'sold_out'], 'date'],
            [['date' => 'texto', 'status' => 'sold_out'], 'date'],
            [['date' => '2026-07-23', 'status' => 'paused'], 'status'],
            [['date' => '2026-07-23', 'status' => 'sold_out', 'reason' => str_repeat('a', 501)], 'reason'],
            [['date' => '2026-07-23', 'status' => 'sold_out', 'replacement_component_id' => 999999], 'replacement_component_id'],
            [['date' => '2026-07-23', 'status' => 'sold_out', 'replacement_component_id' => $component->id], 'replacement_component_id'],
            [['date' => '2026-07-23', 'status' => 'sold_out', 'replacement_component_id' => $inactiveReplacement->id], 'replacement_component_id'],
        ];

        foreach ($invalidPayloads as [$payload, $field]) {
            $this->actingAs($admin)
                ->patchJson($this->globalUrl($component), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->actingAs($admin)
            ->deleteJson($this->globalUrl($component), ['date' => '2026-02-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->actingAs($admin)
            ->patchJson($this->productUrl($this->product('n5-casa'), $component), [
                'date' => '2026-02-31',
                'status' => 'sold_out',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->assertSame(0, DailyComponentAvailability::query()->count());
        $this->assertSame(0, DailyProductComponentOverride::query()->count());
    }

    public function test_global_availability_patch_is_idempotent_and_returns_effective_state(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $secondAdmin = $this->adminUser();
        $component = $this->menuComponent('almondega');
        $replacement = $this->menuComponent('porco');

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
                'reason' => 'Acabou durante o almoco',
                'replacement_component_id' => $replacement->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'global')
            ->assertJsonPath('data.configured_status', 'sold_out')
            ->assertJsonPath('data.reason', 'Acabou durante o almoco')
            ->assertJsonPath('data.replacement.slug', 'porco')
            ->assertJsonPath('data.effective_availability.status', 'sold_out')
            ->assertJsonPath('data.effective_availability.available', false)
            ->assertJsonPath('data.effective_availability.source', 'global_availability');

        $this->assertSame(1, DailyComponentAvailability::query()->count());
        $availability = DailyComponentAvailability::query()->firstOrFail();
        $this->assertSame($this->solRestaurant()->id, $availability->company_id);
        $this->assertSame($component->id, $availability->menu_component_id);
        $this->assertSame('2026-07-23', $availability->availability_date->toDateString());
        $this->assertSame(MenuAvailabilityStatus::SoldOut, $availability->status);
        $this->assertSame($admin->id, $availability->marked_by_user_id);

        $this->actingAs($secondAdmin)
            ->patchJson($this->globalUrl($component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Available->value,
                'reason' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.configured_status', 'available')
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.replacement', null)
            ->assertJsonPath('data.effective_availability.available', true);

        $this->assertSame(1, DailyComponentAvailability::query()->count());
        $availability = DailyComponentAvailability::query()->firstOrFail();
        $this->assertSame('2026-07-23', $availability->availability_date->toDateString());
        $this->assertSame(MenuAvailabilityStatus::Available, $availability->status);
        $this->assertSame($secondAdmin->id, $availability->marked_by_user_id);

        $this->actingAs($secondAdmin)
            ->patchJson($this->globalUrl($component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Unavailable->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.effective_availability.status', 'unavailable')
            ->assertJsonPath('data.effective_availability.available', false);

        $this->assertTrue($component->refresh()->is_active);
    }

    public function test_global_delete_is_idempotent_restores_default_and_preserves_product_overrides(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('almondega');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
        ]);
        DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
        ]);

        $this->actingAs($admin)
            ->deleteJson($this->globalUrl($component), ['date' => '2026-07-23'])
            ->assertOk()
            ->assertJsonPath('data.cleared', true)
            ->assertJsonPath('data.configured_status', null)
            ->assertJsonPath('data.effective_availability.source', 'component_default')
            ->assertJsonPath('data.effective_availability.available', true);

        $this->assertSame(0, DailyComponentAvailability::query()->count());
        $this->assertSame(1, DailyProductComponentOverride::query()->count());

        $this->actingAs($admin)
            ->deleteJson($this->globalUrl($component), ['date' => '2026-07-23'])
            ->assertOk()
            ->assertJsonPath('data.cleared', true)
            ->assertJsonPath('data.effective_availability.source', 'component_default');
    }

    public function test_product_override_patch_and_delete_are_idempotent_and_follow_precedence(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('almondega');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou globalmente',
        ]);

        $this->actingAs($admin)
            ->patchJson($this->productUrl($product, $component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Available->value,
                'reason' => 'Reservado para N5',
            ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'product_override')
            ->assertJsonPath('data.product.slug', 'n5-casa')
            ->assertJsonPath('data.configured_status', 'available')
            ->assertJsonPath('data.effective_availability.source', 'product_override')
            ->assertJsonPath('data.effective_availability.available', true);

        $this->assertSame(1, DailyProductComponentOverride::query()->count());
        $override = DailyProductComponentOverride::query()->firstOrFail();
        $this->assertSame($company->id, $override->company_id);
        $this->assertSame($product->id, $override->product_id);
        $this->assertSame($component->id, $override->menu_component_id);
        $this->assertSame('2026-07-23', $override->availability_date->toDateString());
        $this->assertSame(MenuAvailabilityStatus::Available, $override->status);
        $this->assertSame($admin->id, $override->marked_by_user_id);

        $this->actingAs($admin)
            ->patchJson($this->productUrl($product, $component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.configured_status', 'sold_out')
            ->assertJsonPath('data.effective_availability.status', 'sold_out')
            ->assertJsonPath('data.effective_availability.available', false);

        $this->assertSame(1, DailyProductComponentOverride::query()->count());

        $this->actingAs($admin)
            ->deleteJson($this->productUrl($product, $component), ['date' => '2026-07-23'])
            ->assertOk()
            ->assertJsonPath('data.cleared', true)
            ->assertJsonPath('data.configured_status', null)
            ->assertJsonPath('data.effective_availability.source', 'global_availability')
            ->assertJsonPath('data.effective_availability.status', 'sold_out');

        $this->assertSame(0, DailyProductComponentOverride::query()->count());
        $this->assertSame(1, DailyComponentAvailability::query()->count());

        $this->actingAs($admin)
            ->deleteJson($this->productUrl($product, $component), ['date' => '2026-07-23'])
            ->assertOk()
            ->assertJsonPath('data.effective_availability.source', 'global_availability');
    }

    public function test_product_override_accepts_only_operationally_related_components(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->patchJson($this->productUrl($this->product('n5-casa'), $this->menuComponent('almondega')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Unavailable->value,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson($this->productUrl($this->product('n5-casa'), $this->menuComponent('goiaba')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Unavailable->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('component');

        $this->actingAs($admin)
            ->patchJson($this->productUrl($this->product('n8-tradicional'), $this->menuComponent('alface')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.product.slug', 'n8-tradicional');

        $this->actingAs($admin)
            ->patchJson($this->productUrl($this->product('n9-tradicional'), $this->menuComponent('alface')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.product.slug', 'n9-tradicional');

        $this->actingAs($admin)
            ->patchJson($this->productUrl($this->product('combo-n8-com-latinha'), $this->menuComponent('alface')), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('component');
    }

    public function test_write_then_read_catalog_and_day_reflect_new_state_without_stale_cache(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $company = $this->solRestaurant();
        $beterraba = $this->menuComponent('beterraba');
        $almondega = $this->menuComponent('almondega');

        $this->actingAs($admin)
            ->getJson('/api/app/menu/catalog?date=2026-07-23')
            ->assertOk()
            ->assertJsonPath('data.categories.0.products.0.groups.1.component_options.0.availability.status', 'available');

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($beterraba), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
                'reason' => 'Acabou beterraba',
            ])
            ->assertOk()
            ->assertJsonPath('data.effective_availability.status', 'sold_out');

        $catalog = $this->actingAs($admin)
            ->getJson('/api/app/menu/catalog?date=2026-07-23')
            ->assertOk()
            ->json('data.categories');

        $n5 = $this->productFromCategories($catalog, 'n5-casa');
        $beterrabaOption = collect($this->group($n5, 'salada_casa')['component_options'])
            ->firstWhere('slug', 'beterraba');

        $this->assertFalse($beterrabaOption['available']);
        $this->assertSame('sold_out', $beterrabaOption['availability']['status']);
        $this->assertSame('Acabou beterraba', $beterrabaOption['availability']['reason']);

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($almondega), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertOk();

        $day = $this->actingAs($admin)
            ->getJson('/api/app/menu/day?date=2026-07-23')
            ->assertOk()
            ->json('data');

        $dayAlmondega = collect($day['sections']['meat'])
            ->first(fn (array $item): bool => $item['component']['slug'] === 'almondega');

        $this->assertFalse($dayAlmondega['available']);
        $this->assertSame('sold_out', $dayAlmondega['availability']['status']);

        $counts = [
            DailyComponentAvailability::query()->count(),
            DailyProductComponentOverride::query()->count(),
        ];

        $this->actingAs($admin)->getJson('/api/app/menu/catalog?date=2026-07-23')->assertOk();
        $this->actingAs($admin)->getJson('/api/app/menu/day?date=2026-07-23')->assertOk();

        $this->assertSame($counts, [
            DailyComponentAvailability::query()->count(),
            DailyProductComponentOverride::query()->count(),
        ]);
        $this->assertSame($company->id, $beterraba->refresh()->company_id);
    }

    public function test_delete_then_read_reflects_removal_and_global_fallback(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('almondega');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
        ]);
        DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Available,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23")
            ->assertOk()
            ->assertJsonPath('data.groups.2.component_options.0.availability.source', 'product_override');

        $this->actingAs($admin)
            ->deleteJson($this->productUrl($product, $component), ['date' => '2026-07-23'])
            ->assertOk()
            ->assertJsonPath('data.effective_availability.source', 'global_availability');

        $this->actingAs($admin)
            ->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23")
            ->assertOk()
            ->assertJsonPath('data.groups.2.component_options.0.availability.source', 'global_availability')
            ->assertJsonPath('data.groups.2.component_options.0.available', false);

        $this->actingAs($admin)
            ->deleteJson($this->globalUrl($component), ['date' => '2026-07-23'])
            ->assertOk()
            ->assertJsonPath('data.effective_availability.source', 'component_default');

        $this->actingAs($admin)
            ->getJson("/api/app/menu/products/{$product->id}/configuration?date=2026-07-23")
            ->assertOk()
            ->assertJsonPath('data.groups.2.component_options.0.availability.source', 'component_default')
            ->assertJsonPath('data.groups.2.component_options.0.available', true);
    }

    public function test_writes_preserve_legacy_endpoint_product_options_products_components_and_weekly_items(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->adminUser();
        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('almondega');
        $productBefore = $product->only(['is_active', 'is_available_by_default']);
        $componentBefore = $component->only(['is_active']);
        $weeklyItemsBefore = WeeklyMenuComponentItem::query()->count();
        $legacyOption = ProductOption::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'name' => 'Opcao legada',
            'slug' => 'opcao-legada',
            'option_type' => ProductOption::TYPE_CHOICE,
            'group_code' => 'legado',
            'price_delta_cents' => 123,
        ]);

        $this->actingAs($admin)
            ->patchJson($this->globalUrl($component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::SoldOut->value,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson($this->productUrl($product, $component), [
                'date' => '2026-07-23',
                'status' => MenuAvailabilityStatus::Available->value,
            ])
            ->assertOk();

        $this->assertSame($productBefore, $product->refresh()->only(['is_active', 'is_available_by_default']));
        $this->assertSame($componentBefore, $component->refresh()->only(['is_active']));
        $this->assertSame($weeklyItemsBefore, WeeklyMenuComponentItem::query()->count());
        $this->assertDatabaseHas('product_options', [
            'id' => $legacyOption->id,
            'price_delta_cents' => 123,
        ]);

        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $apiRoutes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringNotContainsString(SolRestaurantStructuredMenuSeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString('menu/components', $apiRoutes);
        $this->assertDirectoryExists(base_path('../frontend'));
    }

    public function test_legacy_available_menu_endpoint_remains_unchanged(): void
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

    private function seedStructuredMenu(): void
    {
        $this->seed([RoleAndPermissionSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['company_id' => $this->solRestaurant()->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return $user;
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

    private function globalUrl(MenuComponent $component): string
    {
        return "/api/app/menu/components/{$component->id}/availability";
    }

    private function productUrl(Product $product, MenuComponent $component): string
    {
        return "/api/app/menu/products/{$product->id}/components/{$component->id}/availability";
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    private function productFromCategories(array $categories, string $slug): array
    {
        foreach ($categories as $category) {
            foreach ($category['products'] as $product) {
                if ($product['slug'] === $slug) {
                    return $product;
                }
            }
        }

        $this->fail("Product {$slug} was not found in structured catalog.");
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
}
