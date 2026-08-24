<?php

namespace App\Services\CounterSales;

use App\Models\Company;
use App\Models\Product;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class CounterSaleProductEligibility
{
    public function __construct(private readonly StructuredProductConfigurationService $configuration) {}

    /**
     * @return Builder<Product>
     */
    public function query(Company $company): Builder
    {
        return Product::query()
            ->with($this->configuration->productRelations())
            ->where('company_id', $company->id)
            ->where('product_type', Product::TYPE_COUNTER)
            ->where('is_active', true)
            ->where('is_available_by_default', true)
            ->where('base_price_cents', '>', 0)
            ->whereHas('category', fn (Builder $categories): Builder => $categories->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name');
    }

    public function isEligible(Company $company, Product $product, CarbonInterface $date): bool
    {
        return (int) $product->company_id === (int) $company->id
            && $product->product_type === Product::TYPE_COUNTER
            && (int) $product->base_price_cents > 0
            && $this->configuration->isSellable($product, $company, $date);
    }
}
