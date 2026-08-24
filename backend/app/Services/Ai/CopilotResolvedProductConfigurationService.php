<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonInterface;

final class CopilotResolvedProductConfigurationService
{
    public function __construct(
        private readonly StructuredProductConfigurationService $products,
        private readonly DailyStructuredMenuService $dailyMenu,
    ) {}

    /** @return array<string,mixed> */
    public function resolve(Company $company, Product $product, CarbonInterface $date): array
    {
        $configuration = $this->products->configuration($product, $company, $date);
        $daily = $this->dailyMenu->day($company, $date);
        $usesDailyMenu = (bool) ($configuration['uses_weekly_menu'] ?? false);

        return [
            'product' => [
                'id' => (int) $product->id,
                'slug' => (string) $product->slug,
                'name' => (string) $product->name,
                'base_price_cents' => (int) $product->base_price_cents,
                'availability' => $configuration['availability'] ?? [],
            ],
            'date' => $date->toDateString(),
            'static_configuration' => $configuration,
            'daily_components' => $this->dailyComponents($daily, $usesDailyMenu),
            'meat_selection' => data_get($configuration, 'meat_configuration.traditional.selection_rules', []),
            'allow_no_meat' => (bool) data_get($configuration, 'meat_configuration.traditional.allow_no_meat', false),
        ];
    }

    /** @param array<string,mixed> $daily @return list<array<string,mixed>> */
    private function dailyComponents(array $daily, bool $appliesToTraditionalProduct): array
    {
        return collect(data_get($daily, 'sections', []))
            ->flatMap(function (mixed $items, string $section) use ($appliesToTraditionalProduct): array {
                return collect(is_array($items) ? $items : [])
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(function (array $item) use ($section, $appliesToTraditionalProduct): array {
                        $available = (bool) ($item['available'] ?? false);
                        $component = is_array($item['component'] ?? null) ? $item['component'] : [];

                        return [
                            'id' => (int) ($component['id'] ?? 0),
                            'slug' => (string) ($component['slug'] ?? ''),
                            'name' => (string) ($component['display_name'] ?? $component['name'] ?? ''),
                            'category' => (string) ($component['component_type'] ?? $section),
                            'section' => $section,
                            'available' => $available,
                            'applicability' => ! $available
                                ? 'UNAVAILABLE_TODAY'
                                : ($appliesToTraditionalProduct ? 'AVAILABLE_TODAY' : 'NOT_APPLICABLE'),
                            // Only meats have a persisted selection rule for N8/N9 today. The other
                            // daily sections remain visible without inventing a cardinality.
                            'selectable' => $appliesToTraditionalProduct && $available && $section === 'meat',
                            'fixed' => false,
                            'removable' => false,
                        ];
                    })
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }
}
