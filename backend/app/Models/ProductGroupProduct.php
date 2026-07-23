<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductGroupProduct extends Model
{
    protected $fillable = [
        'product_option_group_id',
        'selectable_product_id',
        'price_delta_cents',
        'final_price_cents',
        'included_quantity',
        'is_default',
        'is_active',
        'requires_confirmation',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'final_price_cents' => 'integer',
            'included_quantity' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'requires_confirmation' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }

    public function selectableProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'selectable_product_id');
    }
}
