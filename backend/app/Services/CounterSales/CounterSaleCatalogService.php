<?php

namespace App\Services\CounterSales;

use App\Models\Company;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonInterface;

class CounterSaleCatalogService
{
    public function __construct(
        private readonly CounterSaleProductEligibility $eligibility,
        private readonly StructuredProductConfigurationService $configuration,
        private readonly CounterSaleBeefAdditionalResolver $beefAdditional,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function products(Company $company, CarbonInterface $date): array
    {
        $products = $this->eligibility->query($company)
            ->get()
            ->filter(fn ($product): bool => $this->eligibility->isEligible($company, $product, $date));
        $hasConfigurableProduct = $products->contains(fn ($product): bool => data_get($product->metadata, 'pricing_mode') === 'weight'
            || $product->menu_rule_code === 'self_service_counter');
        $beefAdditional = $hasConfigurableProduct ? $this->beefAdditional->resolve($company) : null;

        return $products
            ->map(function ($product) use ($company, $date, $beefAdditional): array {
                $summary = $this->configuration->productSummary($product, $company, $date);
                $acceptsBeefAdditional = $summary['pricing_mode'] === 'weight'
                    || $summary['menu_rule_code'] === 'self_service_counter';

                return [
                    ...$summary,
                    'additions' => $acceptsBeefAdditional && $beefAdditional !== null ? [[
                        'code' => 'extra_beef',
                        'name' => $beefAdditional['name'],
                        'price_cents' => $beefAdditional['price_cents'],
                        'max_quantity' => $beefAdditional['max_quantity'],
                    ]] : [],
                ];
            })
            ->values()
            ->all();
    }
}
