<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends Model
{
    protected $fillable = [
        'order_item_id',
        'product_option_id',
        'name',
        'option_type',
        'group_code',
        'quantity',
        'price_delta_cents',
        'total_price_cents',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_delta_cents' => 'integer',
            'total_price_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class);
    }
}
