<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const TYPE_MARMITA = 'marmita';

    public const TYPE_BEVERAGE = 'beverage';

    public const TYPE_JUICE = 'juice';

    public const TYPE_COMBO = 'combo';

    public const TYPE_FEIJOADA = 'feijoada';

    public const TYPE_ADDON = 'addon';

    protected $fillable = [
        'company_id',
        'category_id',
        'name',
        'slug',
        'product_type',
        'menu_rule_code',
        'description',
        'base_price_cents',
        'currency',
        'is_active',
        'is_available_by_default',
        'allows_item_notes',
        'notes_hint',
        'composition_rules',
        'metadata',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'base_price_cents' => 'integer',
            'is_active' => 'boolean',
            'is_available_by_default' => 'boolean',
            'allows_item_notes' => 'boolean',
            'composition_rules' => 'array',
            'metadata' => 'array',
            'display_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    public function weeklyMenuItems(): HasMany
    {
        return $this->hasMany(WeeklyMenuItem::class);
    }

    public function dailyMenuOverrides(): HasMany
    {
        return $this->hasMany(DailyMenuOverride::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailableByDefault(Builder $query): Builder
    {
        return $query->where('is_available_by_default', true);
    }
}
