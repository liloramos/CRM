<?php

namespace App\Services\Menu;

use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\DailyMenuComponentAdjustment;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuComponentItem;
use Carbon\CarbonInterface;

class AdminMenuReadService
{
    public function __construct(private readonly StructuredProductConfigurationService $products) {}

    /**
     * @return array<string, mixed>
     */
    public function products(Company $company, CarbonInterface $date): array
    {
        $categories = ProductCategory::query()
            ->where('company_id', $company->id)
            ->with([
                'products' => fn ($query) => $query
                    ->with($this->products->productRelations())
                    ->orderBy('display_order')
                    ->orderBy('name'),
            ])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'category_type' => $category->category_type,
                'description' => $category->description,
                'display_order' => $category->display_order,
                'is_active' => (bool) $category->is_active,
                'products' => $category->products
                    ->map(fn (Product $product): array => $this->products->configuration($product, $company, $date))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'date' => $date->toDateString(),
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function components(Company $company): array
    {
        $components = MenuComponent::query()
            ->where('company_id', $company->id)
            ->withCount(['productGroupLinks', 'weeklyMenuItems'])
            ->orderBy('component_type')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MenuComponent $component): array => [
                'id' => $component->id,
                'slug' => $component->slug,
                'name' => $component->name,
                'component_type' => $component->component_type->value,
                'description' => $component->description,
                'default_price_delta_cents' => $component->default_price_delta_cents,
                'is_active' => (bool) $component->is_active,
                'display_order' => $component->display_order,
                'product_group_links_count' => $component->product_group_links_count,
                'weekly_menu_items_count' => $component->weekly_menu_items_count,
            ])
            ->values()
            ->all();

        return [
            'components' => $components,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function weekly(Company $company): array
    {
        $weeklyMenu = WeeklyMenu::query()
            ->where('company_id', $company->id)
            ->where('slug', 'cardapio-semanal-oficial')
            ->first();

        $items = $weeklyMenu instanceof WeeklyMenu
            ? WeeklyMenuComponentItem::query()
                ->with('component')
                ->where('company_id', $company->id)
                ->where('weekly_menu_id', $weeklyMenu->id)
                ->orderBy('service_day')
                ->orderBy('section')
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
            : collect();

        $groupedItems = $items->groupBy(
            fn (WeeklyMenuComponentItem $item): string => $item->service_day->value.'.'.$item->section->value,
        );

        $days = collect(WeeklyMenuServiceDay::cases())
            ->mapWithKeys(fn (WeeklyMenuServiceDay $day): array => [
                $day->value => collect(WeeklyMenuSection::cases())
                    ->mapWithKeys(fn (WeeklyMenuSection $section): array => [
                        $section->value => $groupedItems
                            ->get($day->value.'.'.$section->value, collect())
                            ->map(fn (WeeklyMenuComponentItem $item): array => $this->weeklyItem($item))
                            ->values()
                            ->all(),
                    ])
                    ->all(),
            ])
            ->all();

        return [
            'weekly_menu' => $weeklyMenu ? [
                'id' => $weeklyMenu->id,
                'slug' => $weeklyMenu->slug,
                'name' => $weeklyMenu->name,
                'starts_on' => $weeklyMenu->starts_on?->toDateString(),
                'ends_on' => $weeklyMenu->ends_on?->toDateString(),
                'is_active' => (bool) $weeklyMenu->is_active,
            ] : null,
            'days' => $days,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dayAdjustments(Company $company, CarbonInterface $date): array
    {
        $adjustments = DailyMenuComponentAdjustment::query()
            ->with('component')
            ->where('company_id', $company->id)
            ->whereDate('availability_date', $date->toDateString())
            ->orderBy('section')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (DailyMenuComponentAdjustment $adjustment): array => [
                'id' => $adjustment->id,
                'date' => $adjustment->availability_date->toDateString(),
                'section' => $adjustment->section->value,
                'action' => $adjustment->action->value,
                'display_order' => $adjustment->display_order,
                'notes' => $adjustment->notes,
                'component' => [
                    'id' => $adjustment->component->id,
                    'slug' => $adjustment->component->slug,
                    'name' => $adjustment->component->name,
                    'component_type' => $adjustment->component->component_type->value,
                ],
            ])
            ->values()
            ->all();

        return [
            'date' => $date->toDateString(),
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weeklyItem(WeeklyMenuComponentItem $item): array
    {
        return [
            'id' => $item->id,
            'service_day' => $item->service_day->value,
            'section' => $item->section->value,
            'display_order' => $item->display_order,
            'is_active' => (bool) $item->is_active,
            'notes' => $item->notes,
            'component' => [
                'id' => $item->component->id,
                'slug' => $item->component->slug,
                'name' => $item->component->name,
                'component_type' => $item->component->component_type->value,
            ],
        ];
    }
}
