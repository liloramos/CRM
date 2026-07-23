<?php

namespace App\Services\Menu;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Carbon\CarbonInterface;

class StructuredMenuCatalogService
{
    public function __construct(private readonly StructuredProductConfigurationService $products) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(Company $company, CarbonInterface $date): array
    {
        $categories = ProductCategory::query()
            ->where('company_id', $company->id)
            ->active()
            ->with([
                'products' => fn ($query) => $query
                    ->with($this->products->productRelations())
                    ->orderBy('display_order')
                    ->orderBy('name'),
            ])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(function (ProductCategory $category) use ($company, $date): array {
                $products = $category->products
                    ->filter(fn (Product $product): bool => $this->products->isSellable($product, $company, $date))
                    ->map(fn (Product $product): array => $this->products->configuration($product, $company, $date))
                    ->values()
                    ->all();

                return [
                    'id' => $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'category_type' => $category->category_type,
                    'products' => $products,
                    'display_order' => $category->display_order,
                ];
            })
            ->filter(fn (array $category): bool => $category['products'] !== [])
            ->values()
            ->all();

        return [
            'date' => $date->toDateString(),
            'categories' => $categories,
        ];
    }
}
