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
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function products(Company $company, CarbonInterface $date): array
    {
        return $this->eligibility->query($company)
            ->get()
            ->filter(fn ($product): bool => $this->eligibility->isEligible($company, $product, $date))
            ->map(fn ($product): array => $this->configuration->productSummary($product, $company, $date))
            ->values()
            ->all();
    }
}
