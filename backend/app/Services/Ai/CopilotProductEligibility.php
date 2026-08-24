<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class CopilotProductEligibility
{
    public function apply(Builder $query): Builder
    {
        return $query->where(function (Builder $products): void {
            $products
                ->whereNull('product_type')
                ->orWhere('product_type', '!=', Product::TYPE_COUNTER);
        });
    }

    public function isEligible(?Product $product): bool
    {
        return $product instanceof Product && $product->product_type !== Product::TYPE_COUNTER;
    }
}
