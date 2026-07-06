<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'product_type',
        'menu_rule_code',
        'quantity',
        'unit_price_cents',
        'options_total_cents',
        'total_price_cents',
        'currency',
        'item_notes',
        'beneficiary_name',
        'beneficiary_notes',
        'preferences',
        'restrictions',
        'removed_ingredients',
        'selected_components',
        'substitution_notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'options_total_cents' => 'integer',
            'total_price_cents' => 'integer',
            'preferences' => 'array',
            'restrictions' => 'array',
            'removed_ingredients' => 'array',
            'selected_components' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
