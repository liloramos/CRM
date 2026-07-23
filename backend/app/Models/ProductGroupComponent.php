<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductGroupComponent extends Model
{
    protected $fillable = [
        'product_option_group_id',
        'menu_component_id',
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

    public function component(): BelongsTo
    {
        return $this->belongsTo(MenuComponent::class, 'menu_component_id');
    }
}
