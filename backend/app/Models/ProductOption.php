<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOption extends Model
{
    public const TYPE_ADDON = 'addon';

    public const TYPE_VARIATION = 'variation';

    public const TYPE_CHOICE = 'choice';

    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'slug',
        'option_type',
        'group_code',
        'price_delta_cents',
        'max_quantity',
        'is_required',
        'is_active',
        'rules',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'max_quantity' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'rules' => 'array',
            'display_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItemOptions(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }

    public function dailyMenuOptionOverrides(): HasMany
    {
        return $this->hasMany(DailyMenuOptionOverride::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
