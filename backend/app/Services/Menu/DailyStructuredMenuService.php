<?php

namespace App\Services\Menu;

use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\Product;
use App\Models\WeeklyMenu;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DailyStructuredMenuService
{
    public function __construct(
        private readonly ComponentAvailabilityResolver $availabilityResolver,
        private readonly StructuredMenuCatalogService $catalog,
        private readonly StructuredProductConfigurationService $products,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function day(Company $company, CarbonInterface $date): array
    {
        $company->loadMissing('setting');

        $catalog = $this->catalog->catalog($company, $date);
        $serviceDay = $this->serviceDay($date);

        if ($serviceDay === null) {
            return [
                'date' => $date->toDateString(),
                'service_day' => null,
                'is_service_day' => false,
                'timezone' => $this->timezone($company),
                'weekly_menu' => null,
                'sections' => $this->emptySections(),
                'traditional_products' => $this->traditionalProducts($company, $date),
                'catalog' => $catalog,
            ];
        }

        $weeklyMenu = $this->weeklyMenu($company, $date);

        return [
            'date' => $date->toDateString(),
            'service_day' => $serviceDay->value,
            'is_service_day' => true,
            'timezone' => $this->timezone($company),
            'weekly_menu' => $weeklyMenu ? [
                'id' => $weeklyMenu->id,
                'slug' => $weeklyMenu->slug,
                'name' => $weeklyMenu->name,
                'starts_on' => $weeklyMenu->starts_on?->toDateString(),
                'ends_on' => $weeklyMenu->ends_on?->toDateString(),
            ] : null,
            'sections' => $weeklyMenu
                ? $this->sections($company, $weeklyMenu, $serviceDay, $date)
                : $this->emptySections(),
            'traditional_products' => $this->traditionalProducts($company, $date),
            'catalog' => $catalog,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function sections(
        Company $company,
        WeeklyMenu $weeklyMenu,
        WeeklyMenuServiceDay $serviceDay,
        CarbonInterface $date,
    ): array {
        $itemsBySection = $weeklyMenu->componentItems()
            ->with('component')
            ->where('company_id', $company->id)
            ->where('service_day', $serviceDay->value)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($item): string => $item->section->value);

        return collect(WeeklyMenuSection::cases())
            ->mapWithKeys(fn (WeeklyMenuSection $section): array => [
                $section->value => $this->sectionItems($company, $itemsBySection->get($section->value, collect()), $date),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sectionItems(Company $company, Collection $items, CarbonInterface $date): array
    {
        return $items
            ->map(function ($item) use ($company, $date): array {
                $component = $item->component;
                $availability = $this->availabilityResolver
                    ->resolve($company, $component, $date)
                    ->toArray();

                return [
                    'id' => $item->id,
                    'section' => $item->section->value,
                    'display_order' => $item->display_order,
                    'notes' => $item->notes,
                    'component' => [
                        'id' => $component->id,
                        'slug' => $component->slug,
                        'name' => $component->name,
                        'component_type' => $component->component_type->value,
                    ],
                    'availability' => $availability,
                    'available' => (bool) $availability['available'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function traditionalProducts(Company $company, CarbonInterface $date): array
    {
        return Product::query()
            ->with('category')
            ->where('company_id', $company->id)
            ->whereIn('menu_rule_code', ['n8_tradicional', 'n9_tradicional'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product): bool => $this->products->isSellable($product, $company, $date))
            ->map(fn (Product $product): array => $this->products->productSummary($product, $company, $date))
            ->values()
            ->all();
    }

    private function weeklyMenu(Company $company, CarbonInterface $date): ?WeeklyMenu
    {
        return WeeklyMenu::query()
            ->where('company_id', $company->id)
            ->active()
            ->where(function ($query) use ($date): void {
                $query->whereNull('starts_on')
                    ->orWhereDate('starts_on', '<=', $date->toDateString());
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $date->toDateString());
            })
            ->orderByRaw('starts_on is null')
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->first();
    }

    private function serviceDay(CarbonInterface $date): ?WeeklyMenuServiceDay
    {
        return match ($date->dayOfWeekIso) {
            1 => WeeklyMenuServiceDay::Monday,
            2 => WeeklyMenuServiceDay::Tuesday,
            3 => WeeklyMenuServiceDay::Wednesday,
            4 => WeeklyMenuServiceDay::Thursday,
            5 => WeeklyMenuServiceDay::Friday,
            6 => WeeklyMenuServiceDay::Saturday,
            default => null,
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function emptySections(): array
    {
        return collect(WeeklyMenuSection::cases())
            ->mapWithKeys(fn (WeeklyMenuSection $section): array => [$section->value => []])
            ->all();
    }

    private function timezone(Company $company): string
    {
        return $company->setting?->timezone ?: config('app.timezone');
    }
}
