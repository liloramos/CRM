<?php

namespace App\Services\Menu;

use App\Enums\ProductServiceDay;
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

    public function __construct(
        private readonly ComponentAvailabilityResolver $availabilityResolver,
        private readonly MenuComponentPresentation $components,
    ) {}

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
                'allow_no_meat' => $group->code === 'carne' && in_array($group->code, $this->noMeatGroupCodes($product), true),
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
            'fixed_components_removable' => (bool) data_get($product->composition_rules, 'fixed_components_removable', true),
            'removable_group_codes' => $this->removableGroupCodes($product),
            'allows_item_notes' => (bool) $product->allows_item_notes,
            'notes_hint' => $product->notes_hint,
            'configuration_pending' => $this->hasPendingConfiguration($product),
            'meat_configuration' => $this->meatConfiguration($product, $groups),
            'additions' => $this->additionSummaries($groups),
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
            'serviceDays',
            'optionGroups.componentOptions.component',
            'optionGroups.productOptions.selectableProduct.category',
            'optionGroups.productOptions.selectableProduct.serviceDays',
            'comboItems.includedProduct.category',
            'comboItems.includedProduct.serviceDays',
        ];
    }

    /** @return list<string> */
    private function removableGroupCodes(Product $product): array
    {
        $codes = data_get($product->composition_rules, 'removable_group_codes', []);

        if (! is_array($codes)) {
            return [];
        }

        return collect($codes)
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => trim($code))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function noMeatGroupCodes(Product $product): array
    {
        $codes = data_get($product->composition_rules, 'allow_no_meat_group_codes', []);

        if (! is_array($codes)) {
            return [];
        }

        return collect($codes)
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => trim($code))
            ->unique()
            ->values()
            ->all();
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
            'administrative_status' => $this->administrativeStatus($product),
            'is_legacy' => $this->isLegacyProduct($product),
            'legacy_reason' => $this->legacyReason($product),
            'display_order' => $product->display_order,
            'availability' => $this->productAvailability($product, $company, $date),
            'service_days' => $this->serviceDays($product),
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

        if (! $this->productIsScheduledForDate($product, $date)) {
            return [
                'status' => DailyMenuOverride::STATUS_UNAVAILABLE,
                'available' => false,
                'source' => 'product_service_day',
                'reason' => null,
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
     * @return array<int, string>
     */
    private function serviceDays(Product $product): array
    {
        $product->loadMissing('serviceDays');

        return $product->serviceDays
            ->filter(fn ($serviceDay): bool => (bool) $serviceDay->is_active)
            ->sortBy(fn ($serviceDay): int => $this->serviceDayOrder($serviceDay->service_day->value))
            ->map(fn ($serviceDay): string => $serviceDay->service_day->value)
            ->values()
            ->all();
    }

    private function productIsScheduledForDate(Product $product, CarbonInterface $date): bool
    {
        $product->loadMissing('serviceDays');

        if ($product->serviceDays->isEmpty()) {
            return true;
        }

        $serviceDay = ProductServiceDay::fromDate($date);

        return $product->serviceDays
            ->contains(fn ($row): bool => $row->service_day === $serviceDay && (bool) $row->is_active);
    }

    private function serviceDayOrder(string $serviceDay): int
    {
        return match ($serviceDay) {
            ProductServiceDay::Monday->value => 10,
            ProductServiceDay::Tuesday->value => 20,
            ProductServiceDay::Wednesday->value => 30,
            ProductServiceDay::Thursday->value => 40,
            ProductServiceDay::Friday->value => 50,
            ProductServiceDay::Saturday->value => 60,
            ProductServiceDay::Sunday->value => 70,
            default => 99,
        };
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
            ...$this->components->summary($component),
            'id' => $link->id,
            'component_id' => $component->id,
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

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function meatConfiguration(Product $product, array $groups): ?array
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return null;
        }

        $beefOnly = $this->componentOptionFromGroup($groups, 'variacao_bife', 'bife');

        return [
            'traditional' => [
                'enabled' => true,
                'base_price_cents' => $product->base_price_cents,
                'selection_rules' => [
                    'min' => (int) data_get($product->composition_rules, 'traditional_meat_selection.min_types', 1),
                    'max' => (int) data_get($product->composition_rules, 'traditional_meat_selection.max_types', 2),
                    'same_component_only' => false,
                ],
                'allow_no_meat' => (bool) data_get($product->composition_rules, 'traditional_meat_selection.allow_none', false),
            ],
            'beef_only' => [
                'enabled' => $this->componentOptionIsConfigured($beefOnly),
                'final_price_cents' => $beefOnly['final_price_cents'] ?? null,
                'price_delta_cents' => $beefOnly['price_delta_cents'] ?? null,
                'replaces_traditional_meats' => true,
                'option_id' => $beefOnly['id'] ?? null,
                'component' => $beefOnly ? $this->componentOptionSummary($beefOnly) : null,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function additionSummaries(array $groups): array
    {
        return collect($groups)
            ->filter(fn (array $group): bool => $group['code'] === 'bife_adicional')
            ->flatMap(function (array $group): array {
                return collect($group['component_options'])
                    ->filter(fn (array $option): bool => $option['slug'] === 'bife')
                    ->map(fn (array $option): array => [
                        'code' => 'extra_beef',
                        'group_code' => $group['code'],
                        'name' => 'Bife adicional',
                        'enabled' => $this->componentOptionIsConfigured($option),
                        'price_cents' => $option['price_delta_cents'],
                        'price_delta_cents' => $option['price_delta_cents'],
                        'max_quantity' => $group['max_quantity'] ?? 1,
                        'requires_traditional_meats' => true,
                        'option_id' => $option['id'],
                        'component' => $this->componentOptionSummary($option),
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function componentOptionFromGroup(array $groups, string $groupCode, string $componentSlug): ?array
    {
        $group = collect($groups)->firstWhere('code', $groupCode);

        if (! is_array($group)) {
            return null;
        }

        $option = collect($group['component_options'])->firstWhere('slug', $componentSlug);

        return is_array($option) ? $option : null;
    }

    /**
     * @param  array<string, mixed>|null  $option
     */
    private function componentOptionIsConfigured(?array $option): bool
    {
        return is_array($option)
            && (bool) $option['link_active']
            && ! (bool) $option['requires_confirmation'];
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function componentOptionSummary(array $option): array
    {
        return [
            'id' => $option['component_id'],
            'slug' => $option['slug'],
            'name' => $option['name'],
            'display_name' => $option['display_name'],
            'supporting_name' => $option['supporting_name'],
            'search_aliases' => $option['search_aliases'],
            'component_type' => $option['component_type'],
        ];
    }

    private function administrativeStatus(Product $product): string
    {
        if ($this->isLegacyProduct($product)) {
            return 'legacy';
        }

        return $product->is_active ? 'active' : 'inactive';
    }

    private function isLegacyProduct(Product $product): bool
    {
        return (bool) data_get($product->metadata, 'official_price_pending', false)
            && ! $product->is_active
            && $product->base_price_cents === null;
    }

    private function legacyReason(Product $product): ?string
    {
        if (! $this->isLegacyProduct($product)) {
            return null;
        }

        return 'Registro legado preservado para historico; nao representa uma feijoada oficial vendavel.';
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
