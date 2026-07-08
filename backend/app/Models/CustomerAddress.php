<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAddress extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'postal_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'country_code',
        'reference',
        'latitude',
        'longitude',
        'is_default',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_address_id');
    }

    public function deliveryQuotes(): HasMany
    {
        return $this->hasMany(DeliveryQuote::class);
    }
}
