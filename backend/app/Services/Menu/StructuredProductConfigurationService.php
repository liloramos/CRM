<?php

namespace App\Services\Menu;

use App\Models\Company;
use App\Models\DailyMenuOverride;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class StructuredProductConfigurationService
{
    /**
     * @var array<int, array<string, Collection<int, DailyMenuOverride>>>
     */
    private array $dailyProductOverrideCache = [];

    public function __construct(private readonly ComponentAvailabilityResolver $availabilityResolver) {}

    /**
     * @return array<string, mixed>
     */
    public function configuration(Product $product, Company $company, CarbonInterface $date): array
    {
        $product->loadMissing($this->productRelations());

        $groups = $product->optionGroups
            ->sortBy([['display_order', 'asc'], ['id', 'asc']])
            ->map(fn ($group): array => [
                'id' => $group->id,
                'code' => $group->code,
                'label' => $group->label,
                'selection_mode' => $group->selection_mode->value,
                'selection_actor' => $group->selection_actor->value,
                'required' => (bool) $group->is_required,
                'min_choices' => $group->min_choices,
                'max_choices' => $group->max_choices,
                'min_quantity' => $group->min_quantity,
                'max_quantity' => $group->max_quantity,
                'same_component_only' => (bool) $group->same_component_only,
                'included_in_base_price' => (bool) $group->included_in_base_price,
                'component_options' => $group->componentOptions
                    ->sortBy([['display_order', 'asc'], ['id', 'asc']])
                    ->map(fn ($link): array => $this->componentOption($link, $company, $product, $date))
                    ->values()
                    ->all(),
                'product_options' => $group->productOptions
                    ->sortBy([['display_order', 'asc'], ['id', 'asc']])
                    ->map(fn ($link): array => $this->productOption($link, $company, $date))
                    ->values()
                    ->all(),
                'display_order' => $group->display_order,
            ])
            ->values()
            ->all();

        return [
            ...$this->productSummary($product, $company, $date),
            'description' => $product->description,
            'menu_rule_code' => $product->menu_rule_code,
            'uses_weekly_menu' => $this->usesWeeklyMenu($product),
            'allows_item_notes' => (bool) $product->allows_item_notes,
            'notes_hint' => $product->notes_hint,
            'configuration_pending' => $this->hasPendingConfiguration($product),
            'groups' => $groups,
            'combo_items' => $product->comboItems
                ->sortBy([['display_order', 'asc'], ['id', 'asc']])
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'included_product' => $this->productSummary($item->includedProduct, $company, $date),
                    'quantity' => $item->quantity,
                    'price_behavior' => $item->price_behavior->value,
                    'price_delta_cents' => $item->price_delta_cents,
                    'print_mode' => $item->print_mode->value,
                    'display_order' => $item->display_order,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function productRelations(): array
    {
        return [
            'category',
            'optionGroups.componentOptions.component',
            'optionGroups.productOptions.selectableProduct.category',
            'comboItems.includedProduct.category',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productSummary(Product $product, Company $company, CarbonInterface $date): array
    {
        $product->loadMissing('category');

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'product_type' => $product->product_type,
            'base_price_cents' => $product->base_price_cents,
            'currency' => $product->currency,
            'is_active' => (bool) $product->is_active,
            'is_available_by_default' => (bool) $product->is_available_by_default,
            'availability' => $this->productAvailability($product, $company, $date),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'slug' => $product->category->slug,
                'name' => $product->category->name,
                'category_type' => $product->category->category_type,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productAvailability(Product $product, Company $company, CarbonInterface $date): array
    {
        $dateString = $date->toDateString();
        $categoryActive = $product->category === null || (bool) $product->category->is_active;

        if (! $product->is_active || ! $categoryActive) {
            return [
                'status' => DailyMenuOverride::STATUS_UNAVAILABLE,
                'available' => false,
                'source' => 'product_default',
                'reason' => null,
                'availability_date' => $dateString,
            ];
        }

        $override = $this->productOverrides($company, $dateString)->get((int) $product->id);

        if ($override instanceof DailyMenuOverride) {
            return [
                'status' => $override->status,
                'available' => $override->status === DailyMenuOverride::STATUS_AVAILABLE,
                'source' => 'daily_menu_override',
                'reason' => $override->reason,
                'availability_date' => $dateString,
            ];
        }

        return [
            'status' => $product->is_available_by_default
                ? DailyMenuOverride::STATUS_AVAILABLE
                : DailyMenuOverride::STATUS_UNAVAILABLE,
            'available' => (bool) $product->is_available_by_default,
            'source' => 'product_default',
            'reason' => null,
            'availability_date' => $dateString,
        ];
    }

    public function isSellable(Product $product, Company $company, CarbonInterface $date): bool
    {
        return (bool) $this->productAvailability($product, $company, $date)['available'];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentOption($link, Company $company, Product $product, CarbonInterface $date): array
    {
        $component = $link->component;
        $availability = $this->availabilityResolver
            ->resolve($company, $component, $date, $product)
            ->toArray();

        return [
            'id' => $link->id,
            'component_id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'component_type' => $component->component_type->value,
            'price_delta_cents' => $link->price_delta_cents,
            'final_price_cents' => $link->final_price_cents,
            'included_quantity' => $link->included_quantity,
            'is_default' => (bool) $link->is_default,
            'requires_confirmation' => (bool) $link->requires_confirmation,
            'link_active' => (bool) $link->is_active,
            'available' => (bool) $link->is_active && (bool) $availability['available'],
            'availability' => $availability,
            'display_order' => $link->display_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productOption($link, Company $company, CarbonInterface $date): array
    {
        $selectableProduct = $link->selectableProduct;
        $availability = $this->productAvailability($selectableProduct, $company, $date);

        return [
            'id' => $link->id,
            'selectable_product' => $this->productSummary($selectableProduct, $company, $date),
            'price_delta_cents' => $link->price_delta_cents,
            'final_price_cents' => $link->final_price_cents,
            'included_quantity' => $link->included_quantity,
            'is_default' => (bool) $link->is_default,
            'requires_confirmation' => (bool) $link->requires_confirmation,
            'link_active' => (bool) $link->is_active,
            'available' => (bool) $link->is_active && (bool) $availability['available'],
            'availability' => $availability,
            'display_order' => $link->display_order,
        ];
    }

    private function usesWeeklyMenu(Product $product): bool
    {
        if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return true;
        }

        return (bool) data_get($product->composition_rules, 'uses_weekly_menu', false);
    }

    private function hasPendingConfiguration(Product $product): bool
    {
        return $product->optionGroups->contains(function ($group): bool {
            return $group->componentOptions->contains(fn ($link): bool => ! $link->is_active && $link->requires_confirmation)
                || $group->productOptions->contains(fn ($link): bool => ! $link->is_active && $link->requires_confirmation);
        });
    }

    /**
     * @return Collection<int, DailyMenuOverride>
     */
    private function productOverrides(Company $company, string $dateString): Collection
    {
        $companyId = (int) $company->id;

        if (! isset($this->dailyProductOverrideCache[$companyId][$dateString])) {
            $this->dailyProductOverrideCache[$companyId][$dateString] = DailyMenuOverride::query()
                ->where('company_id', $companyId)
                ->whereDate('availability_date', $dateString)
                ->get()
                ->keyBy('product_id');
        }

        return $this->dailyProductOverrideCache[$companyId][$dateString];
    }
}
