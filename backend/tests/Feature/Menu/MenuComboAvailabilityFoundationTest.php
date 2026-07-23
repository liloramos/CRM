<?php

namespace Tests\Feature\Menu;

use App\Enums\ComboItemPriceBehavior;
use App\Enums\ComboItemPrintMode;
use App\Enums\MenuAvailabilityStatus;
use App\Models\ComboItem;
use App\Models\Company;
use App\Models\DailyComponentAvailability;
use App\Models\DailyProductComponentOverride;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\User;
use App\Models\WeeklyMenuComponentItem;
use Database\Seeders\SolRestaurantComboCompositionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class MenuComboAvailabilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_combo_and_availability_schema_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('combo_items'));
        $this->assertTrue(Schema::hasTable('daily_component_availability'));
        $this->assertTrue(Schema::hasTable('daily_product_component_overrides'));

        $this->assertTrue(Schema::hasColumns('combo_items', [
            'company_id',
            'combo_product_id',
            'included_product_id',
            'quantity',
            'price_behavior',
            'price_delta_cents',
            'print_mode',
            'display_order',
        ]));

        $this->assertTrue(Schema::hasColumns('daily_component_availability', [
            'company_id',
            'menu_component_id',
            'availability_date',
            'status',
            'reason',
            'replacement_component_id',
            'marked_by_user_id',
        ]));

        $this->assertTrue(Schema::hasColumns('daily_product_component_overrides', [
            'company_id',
            'product_id',
            'menu_component_id',
            'availability_date',
            'status',
            'reason',
            'marked_by_user_id',
        ]));
    }

    public function test_combo_item_enum_casts_and_relationships(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $company = $this->solRestaurant();
        $combo = $this->product('combo-n8-casa-baby');
        $included = $this->product('n8-casa');
        $comboItem = ComboItem::query()
            ->whereBelongsTo($combo, 'comboProduct')
            ->whereBelongsTo($included, 'includedProduct')
            ->firstOrFail();

        $this->assertSame(ComboItemPriceBehavior::Included, $comboItem->price_behavior);
        $this->assertSame(ComboItemPrintMode::ChildLine, $comboItem->print_mode);
        $this->assertTrue($comboItem->company()->first()->is($company));
        $this->assertTrue($comboItem->comboProduct()->first()->is($combo));
        $this->assertTrue($comboItem->includedProduct()->first()->is($included));
        $this->assertTrue($company->comboItems()->first()->is($comboItem));
        $this->assertTrue($combo->comboItems()->first()->is($comboItem));
        $this->assertTrue($included->includedInComboItems()->first()->is($comboItem));
    }

    public function test_component_availability_casts_and_relationships(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $component = $this->menuComponent('almondega');
        $replacement = $this->menuComponent('porco');
        $product = $this->product('n5-casa');
        $user = User::factory()->create(['company_id' => $company->id]);

        $availability = DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou durante a operação.',
            'replacement_component_id' => $replacement->id,
            'marked_by_user_id' => $user->id,
        ]);

        $override = DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
            'reason' => 'Não oferecer na N5 Casa nesta data.',
            'marked_by_user_id' => $user->id,
        ]);

        $this->assertSame(MenuAvailabilityStatus::SoldOut, $availability->refresh()->status);
        $this->assertSame('2026-07-23', $availability->availability_date->toDateString());
        $this->assertSame(MenuAvailabilityStatus::Unavailable, $override->refresh()->status);
        $this->assertSame('2026-07-23', $override->availability_date->toDateString());

        $this->assertTrue($company->dailyComponentAvailabilities()->first()->is($availability));
        $this->assertTrue($company->dailyProductComponentOverrides()->first()->is($override));
        $this->assertTrue($component->dailyAvailabilities()->first()->is($availability));
        $this->assertTrue($replacement->replacementAvailabilities()->first()->is($availability));
        $this->assertTrue($component->productAvailabilityOverrides()->first()->is($override));
        $this->assertTrue($product->componentAvailabilityOverrides()->first()->is($override));
        $this->assertTrue($user->markedComponentAvailabilities()->first()->is($availability));
        $this->assertTrue($user->markedProductComponentOverrides()->first()->is($override));
        $this->assertTrue($availability->company()->first()->is($company));
        $this->assertTrue($availability->component()->first()->is($component));
        $this->assertTrue($availability->replacementComponent()->first()->is($replacement));
        $this->assertTrue($availability->markedByUser()->first()->is($user));
        $this->assertTrue($override->company()->first()->is($company));
        $this->assertTrue($override->product()->first()->is($product));
        $this->assertTrue($override->component()->first()->is($component));
        $this->assertTrue($override->markedByUser()->first()->is($user));
    }

    public function test_combo_items_unique_quantity_and_self_reference_rules(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $company = $this->solRestaurant();
        $combo = $this->product('combo-n8-casa-baby');
        $included = $this->product('n8-casa');

        $this->expectException(UniqueConstraintViolationException::class);
        ComboItem::query()->create([
            'company_id' => $company->id,
            'combo_product_id' => $combo->id,
            'included_product_id' => $included->id,
            'quantity' => 1,
            'price_behavior' => ComboItemPriceBehavior::Included,
            'print_mode' => ComboItemPrintMode::ChildLine,
        ]);
    }

    public function test_combo_item_quantity_must_be_greater_than_zero(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->expectException(InvalidArgumentException::class);

        ComboItem::query()->create([
            'company_id' => $this->solRestaurant()->id,
            'combo_product_id' => $this->product('combo-n8-casa-baby')->id,
            'included_product_id' => $this->product('n8-casa')->id,
            'quantity' => 0,
            'price_behavior' => ComboItemPriceBehavior::Included,
            'print_mode' => ComboItemPrintMode::ChildLine,
        ]);
    }

    public function test_combo_item_cannot_include_its_own_combo_product(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $combo = $this->product('combo-n8-casa-baby');

        $this->expectException(InvalidArgumentException::class);

        ComboItem::query()->create([
            'company_id' => $this->solRestaurant()->id,
            'combo_product_id' => $combo->id,
            'included_product_id' => $combo->id,
            'quantity' => 1,
            'price_behavior' => ComboItemPriceBehavior::Included,
            'print_mode' => ComboItemPrintMode::ChildLine,
        ]);
    }

    public function test_daily_component_availability_unique_and_replacement_rules(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $component = $this->menuComponent('almondega');

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
        ]);
    }

    public function test_daily_component_replacement_cannot_point_to_itself(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $component = $this->menuComponent('almondega');

        $this->expectException(InvalidArgumentException::class);

        DailyComponentAvailability::query()->create([
            'company_id' => $this->solRestaurant()->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
            'replacement_component_id' => $component->id,
        ]);
    }

    public function test_daily_product_component_override_is_unique(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $product = $this->product('n5-casa');
        $component = $this->menuComponent('almondega');

        DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
        ]);
    }

    public function test_combo_n8_casa_baby_has_exact_fixed_composition(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $this->assertSame(['n8-casa', 'guarana-mineiro-baby'], $this->comboIncludedSlugs('combo-n8-casa-baby'));

        foreach ($this->comboItems('combo-n8-casa-baby') as $index => $item) {
            $this->assertSame(1, $item->quantity);
            $this->assertSame(ComboItemPriceBehavior::Included, $item->price_behavior);
            $this->assertSame(0, $item->price_delta_cents);
            $this->assertSame(ComboItemPrintMode::ChildLine, $item->print_mode);
            $this->assertSame(($index + 1) * 10, $item->display_order);
        }
    }

    public function test_combo_n8_com_latinha_has_only_n8_tradicional_as_fixed_item(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $this->assertSame(['n8-tradicional'], $this->comboIncludedSlugs('combo-n8-com-latinha'));

        $item = $this->comboItems('combo-n8-com-latinha')->first();
        $this->assertSame(1, $item->quantity);
        $this->assertSame(ComboItemPriceBehavior::Included, $item->price_behavior);
        $this->assertSame(0, $item->price_delta_cents);
        $this->assertSame(ComboItemPrintMode::ChildLine, $item->print_mode);
        $this->assertSame(10, $item->display_order);
    }

    public function test_combo_latinha_does_not_duplicate_variable_beverage_in_combo_items(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame(['n8-tradicional'], $this->comboIncludedSlugs('combo-n8-com-latinha'));
        $this->assertSame(
            ['guarana-lata', 'mineiro-lata', 'coca-cola-lata-normal', 'coca-cola-zero-lata', 'mineiro-lata-zero'],
            $this->productGroupSlugs('combo-n8-com-latinha', 'bebida_combo'),
        );
    }

    public function test_combo_baby_has_no_artificial_beverage_group(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertSame([], DB::table('product_option_groups')
            ->where('product_id', $this->product('combo-n8-casa-baby')->id)
            ->pluck('code')
            ->all());
    }

    public function test_combo_composition_seeder_is_idempotent_and_preserves_prices(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $this->assertSame(3, ComboItem::query()->count());
        $this->assertDatabaseHas('products', ['slug' => 'combo-n8-casa-baby', 'base_price_cents' => 1500]);
        $this->assertDatabaseHas('products', ['slug' => 'combo-n8-com-latinha', 'base_price_cents' => 2000]);

        foreach (ComboItem::query()->get() as $item) {
            $this->assertSame(0, $item->price_delta_cents);
            $this->assertSame(ComboItemPriceBehavior::Included, $item->price_behavior);
        }
    }

    public function test_legacy_generic_latinhas_do_not_enter_combo_compositions(): void
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

        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $this->assertNotContains('latinha', $this->comboIncludedSlugs('combo-n8-com-latinha'));
        $this->assertNotContains('latinha-zero', $this->comboIncludedSlugs('combo-n8-com-latinha'));
        $this->assertNotContains('latinha', $this->productGroupSlugs('combo-n8-com-latinha', 'bebida_combo'));
        $this->assertNotContains('latinha-zero', $this->productGroupSlugs('combo-n8-com-latinha', 'bebida_combo'));
    }

    public function test_global_component_availability_and_product_override_can_coexist(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $company = $this->solRestaurant();
        $component = $this->menuComponent('almondega');

        $availability = DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::SoldOut,
            'reason' => 'Acabou durante a operação.',
            'replacement_component_id' => $this->menuComponent('porco')->id,
        ]);

        $override = DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $this->product('n5-casa')->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Unavailable,
            'reason' => 'Exceção apenas na N5 Casa.',
        ]);

        $this->assertSame('Acabou durante a operação.', $availability->reason);
        $this->assertSame('Exceção apenas na N5 Casa.', $override->reason);
        $this->assertSame(1, DailyComponentAvailability::query()->count());
        $this->assertSame(1, DailyProductComponentOverride::query()->count());
    }

    public function test_marked_by_user_is_optional_for_availability_tables(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $company = $this->solRestaurant();
        $component = $this->menuComponent('almondega');

        $availability = DailyComponentAvailability::query()->create([
            'company_id' => $company->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Available,
        ]);

        $override = DailyProductComponentOverride::query()->create([
            'company_id' => $company->id,
            'product_id' => $this->product('n5-casa')->id,
            'menu_component_id' => $component->id,
            'availability_date' => '2026-07-23',
            'status' => MenuAvailabilityStatus::Available,
        ]);

        $this->assertNull($availability->marked_by_user_id);
        $this->assertNull($override->marked_by_user_id);
    }

    public function test_seeders_do_not_create_availability_records_or_overrides(): void
    {
        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $this->assertSame(0, DailyComponentAvailability::query()->count());
        $this->assertSame(0, DailyProductComponentOverride::query()->count());
    }

    public function test_legacy_product_options_weekly_menu_items_and_products_are_preserved(): void
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

        $weeklyItemsBefore = WeeklyMenuComponentItem::query()->count();
        $productsBefore = Product::query()->count();

        $this->seed(SolRestaurantComboCompositionSeeder::class);

        $option->refresh();
        $this->assertSame('Opção legada', $option->name);
        $this->assertSame(123, $option->price_delta_cents);
        $this->assertSame($weeklyItemsBefore, WeeklyMenuComponentItem::query()->count());
        $this->assertSame($productsBefore, Product::query()->count());
    }

    public function test_new_combo_availability_seeders_are_not_registered_and_no_routes_or_frontend_are_added(): void
    {
        $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $apiRoutes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringNotContainsString(SolRestaurantComboCompositionSeeder::class, $databaseSeeder);
        $this->assertStringNotContainsString('combo-items', $apiRoutes);
        $this->assertStringNotContainsString('component-availability', $apiRoutes);
        $this->assertDirectoryExists(base_path('../frontend'));
    }

    /**
     * @return Collection<int, ComboItem>
     */
    private function comboItems(string $comboSlug)
    {
        return $this->product($comboSlug)
            ->comboItems()
            ->with('includedProduct')
            ->orderBy('display_order')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function comboIncludedSlugs(string $comboSlug): array
    {
        return $this->comboItems($comboSlug)
            ->map(fn (ComboItem $item): string => $item->includedProduct->slug)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function productGroupSlugs(string $productSlug, string $groupCode): array
    {
        $group = $this->product($productSlug)
            ->optionGroups()
            ->where('code', $groupCode)
            ->firstOrFail();

        return $group
            ->productOptions()
            ->with('selectableProduct')
            ->orderBy('display_order')
            ->get()
            ->map(fn ($link): string => $link->selectableProduct->slug)
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

    private function menuComponent(string $slug): MenuComponent
    {
        return MenuComponent::query()
            ->where('company_id', $this->solRestaurant()->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
