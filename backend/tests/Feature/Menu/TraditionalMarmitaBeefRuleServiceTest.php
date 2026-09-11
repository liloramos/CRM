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

        $this->assertSame(1900, $traditional['total_cents']);
        $this->assertSame(2300, $beefOnly['total_cents']);
        $this->assertSame(2600, $withExtraBeef['total_cents']);
    }

    public function test_churrasco_with_a_second_standard_meat_has_the_configured_additional_price(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $quote = app(TraditionalMarmitaBeefRuleService::class)->quote($this->product('n9-tradicional'), [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [$this->menuComponent('churrasco')->id, $this->menuComponent('porco')->id],
        ]);

        $this->assertSame(2300, $quote['total_cents']);
        $this->assertSame(400, $quote['standard_meat_additional_total_cents']);
    }

    public function test_third_standard_meat_uses_the_configured_additional_meat_price(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);

        $quote = app(TraditionalMarmitaBeefRuleService::class)->quote($this->product('n9-tradicional'), [
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [
                $this->menuComponent('feijoada')->id,
                $this->menuComponent('porco')->id,
                $this->menuComponent('frango-ao-molho')->id,
            ],
        ]);

        $this->assertSame(2300, $quote['total_cents']);
        $this->assertSame(400, $quote['standard_meat_additional_total_cents']);
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
            'traditional_meat_component_ids' => [],
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
