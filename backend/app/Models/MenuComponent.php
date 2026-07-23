<?php

namespace App\Models;

use App\Enums\MenuComponentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuComponent extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'component_type',
        'description',
        'default_price_delta_cents',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'component_type' => MenuComponentType::class,
            'default_price_delta_cents' => 'integer',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function productGroupLinks(): HasMany
    {
        return $this->hasMany(ProductGroupComponent::class);
    }

    public function weeklyMenuItems(): HasMany
    {
        return $this->hasMany(WeeklyMenuComponentItem::class);
    }

    public function dailyAvailabilities(): HasMany
    {
        return $this->hasMany(DailyComponentAvailability::class);
    }

    public function replacementAvailabilities(): HasMany
    {
        return $this->hasMany(DailyComponentAvailability::class, 'replacement_component_id');
    }

    public function productAvailabilityOverrides(): HasMany
    {
        return $this->hasMany(DailyProductComponentOverride::class);
    }
}
