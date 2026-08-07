<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    public const DEMO_EMAIL = 'cliente.exemplo@example.test';

    public const DASHBOARD_DEMO_EMAIL_PREFIX = 'dashboard.demo.';

    public const SOURCE_CHANNEL_DEMO = 'demo';

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'whatsapp_id',
        'whatsapp_profile_name',
        'last_whatsapp_at',
        'email',
        'notes',
        'source_channel',
        'credit_balance_cents',
        'credit_currency',
    ];

    protected function casts(): array
    {
        return [
            'credit_balance_cents' => 'integer',
            'last_whatsapp_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function payerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'payer_customer_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }

    public function isDemoRecord(): bool
    {
        return $this->source_channel === self::SOURCE_CHANNEL_DEMO
            || $this->email === self::DEMO_EMAIL;
    }
}
