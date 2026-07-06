<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliverySetting extends Model
{
    public const CALCULATION_MANUAL_DISTANCE = 'manual_distance';

    public const MAPS_PROVIDER_NONE = 'none';

    public const ROUNDING_NEAREST_CENT = 'nearest_cent';

    public const DEFAULT_PRICE_PER_KM_CENTS = 200;

    public const DEFAULT_SURCHARGE_PERCENT = 10.0;

    protected $fillable = [
        'company_id',
        'is_active',
        'calculation_mode',
        'price_per_km_cents',
        'surcharge_percent',
        'minimum_fee_cents',
        'maximum_distance_km',
        'rounding_mode',
        'maps_provider',
        'provider_options',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_per_km_cents' => 'integer',
            'surcharge_percent' => 'decimal:2',
            'minimum_fee_cents' => 'integer',
            'maximum_distance_km' => 'decimal:3',
            'provider_options' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(DeliveryQuote::class);
    }
}
