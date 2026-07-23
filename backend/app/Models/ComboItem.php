<?php

namespace App\Models;

use App\Enums\ComboItemPriceBehavior;
use App\Enums\ComboItemPrintMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ComboItem extends Model
{
    protected $fillable = [
        'company_id',
        'combo_product_id',
        'included_product_id',
        'quantity',
        'price_behavior',
        'price_delta_cents',
        'print_mode',
        'display_order',
    ];

    protected static function booted(): void
    {
        static::saving(function (ComboItem $comboItem): void {
            if ($comboItem->quantity < 1) {
                throw new InvalidArgumentException('Combo item quantity must be greater than zero.');
            }

            if ((int) $comboItem->combo_product_id === (int) $comboItem->included_product_id) {
                throw new InvalidArgumentException('A combo item cannot include its own combo product.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_behavior' => ComboItemPriceBehavior::class,
            'price_delta_cents' => 'integer',
            'print_mode' => ComboItemPrintMode::class,
            'display_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function comboProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'combo_product_id');
    }

    public function includedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'included_product_id');
    }
}
