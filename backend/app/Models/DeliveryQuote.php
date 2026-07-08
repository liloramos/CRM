<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryQuote extends Model
{
    public const STATUS_QUOTED = 'quoted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'order_id',
        'customer_address_id',
        'delivery_setting_id',
        'quoted_by_user_id',
        'fulfillment_type',
        'status',
        'distance_km',
        'price_per_km_cents',
        'base_fee_cents',
        'surcharge_percent',
        'surcharge_cents',
        'delivery_fee_cents',
        'currency',
        'calculation_mode',
        'maps_provider',
        'external_route_id',
        'delivery_address_snapshot',
        'recipient_name',
        'recipient_phone',
        'address_reference',
        'delivery_notes',
        'maps_metadata',
        'quoted_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:3',
            'price_per_km_cents' => 'integer',
            'base_fee_cents' => 'integer',
            'surcharge_percent' => 'decimal:2',
            'surcharge_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'delivery_address_snapshot' => 'array',
            'maps_metadata' => 'array',
            'quoted_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function deliverySetting(): BelongsTo
    {
        return $this->belongsTo(DeliverySetting::class);
    }

    public function quotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by_user_id');
    }
}
