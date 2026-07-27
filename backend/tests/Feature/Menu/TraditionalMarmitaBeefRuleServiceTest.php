<?php

namespace Tests\Feature\Menu;

use App\Models\MenuComponent;
use App\Models\Product;
use App\Services\Menu\TraditionalMarmitaBeefRuleService;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TraditionalMarmitaBeefRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_n8_traditional_beef_modes_have_confirmed_prices(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $product = $this->product('n8-tradicional');
        $meats = [$this->menuComponent('porco')->id, $this->menuComponent('frango-ao-molho')->id];
        $service = app(TraditionalMarmitaBeefRuleService::class);

        $traditional = $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => $meats,
        ]);
        $beefOnly = $service->quote($product, [
            'meat_mode' => 'beef_only',
            'submitted_total_cents' => 9999,
        ]);
        $withExtraBeef = $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => $meats,
            'extra_beef_quantity' => 1,
            'submitted_total_cents' => 9999,
        ]);

        $this->assertSame(1600, $traditional['total_cents']);
        $this->assertSame(2000, $beefOnly['total_cents']);
        $this->assertSame(2300, $withExtraBeef['total_cents']);
    }

    public function test_n9_traditional_beef_modes_have_confirmed_prices(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $product = $this->product('n9-tradicional');
        $meats = [$this->menuComponent('almondega')->id, $this->menuComponent('porco')->id];
        $service = app(TraditionalMarmitaBeefRuleService::class);

        $traditional = $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => $meats,
        ]);
        $beefOnly = $service->quote($product, [
            'meat_mode' => 'beef_only',
        ]);
        $withExtraBeef = $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => $meats,
            'extra_beef_quantity' => 1,
        ]);

        $this->assertSame(1800, $traditional['total_cents']);
        $this->assertSame(2200, $beefOnly['total_cents']);
        $this->assertSame(2500, $withExtraBeef['total_cents']);
    }

    public function test_beef_only_rejects_traditional_meats_and_extra_beef(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $service = app(TraditionalMarmitaBeefRuleService::class);
        $product = $this->product('n8-tradicional');

        $this->assertValidationError(fn () => $service->quote($product, [
            'meat_mode' => 'beef_only',
            'traditional_meat_component_ids' => [$this->menuComponent('porco')->id],
        ]), 'traditional_meat_component_ids');

        $this->assertValidationError(fn () => $service->quote($product, [
            'meat_mode' => 'beef_only',
            'extra_beef_quantity' => 1,
        ]), 'extra_beef_quantity');
    }

    public function test_traditional_mode_rejects_incomplete_or_invalid_meats(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $service = app(TraditionalMarmitaBeefRuleService::class);
        $product = $this->product('n9-tradicional');

        $this->assertValidationError(fn () => $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [$this->menuComponent('porco')->id],
        ]), 'traditional_meat_component_ids');

        $this->assertValidationError(fn () => $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [$this->menuComponent('porco')->id, $this->menuComponent('bife')->id],
        ]), 'traditional_meat_component_ids');
    }

    public function test_extra_beef_rejects_quantity_above_one(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $service = app(TraditionalMarmitaBeefRuleService::class);
        $product = $this->product('n8-tradicional');

        $this->assertValidationError(fn () => $service->quote($product, [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [$this->menuComponent('porco')->id, $this->menuComponent('frango-ao-molho')->id],
            'extra_beef_quantity' => 2,
        ]), 'extra_beef_quantity');
    }

    public function test_no_duplicate_beef_components_are_created_by_seeders(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $companyId = $this->product('n8-tradicional')->company_id;

        $this->assertSame(1, MenuComponent::query()
            ->where('company_id', $companyId)
            ->where('slug', 'bife')
            ->count());
    }

    private function product(string $slug): Product
    {
        return Product::query()->where('slug', $slug)->firstOrFail();
    }

    private function menuComponent(string $slug): MenuComponent
    {
        return MenuComponent::query()->where('slug', $slug)->firstOrFail();
    }

    private function assertValidationError(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail("Expected validation error for {$field}.");
    }
}
