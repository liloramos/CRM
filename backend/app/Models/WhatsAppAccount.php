<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    public const PROVIDER_FAKE = 'fake';

    public const PROVIDER_META_CLOUD = 'meta_cloud';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_PENDING_CONFIGURATION = 'pending_configuration';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'company_id',
        'provider',
        'name',
        'phone_number_id',
        'business_account_id',
        'display_phone_number',
        'status',
        'connection_status_message',
        'is_default',
        'webhook_verified_at',
        'last_webhook_at',
        'connected_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'webhook_verified_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'connected_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WhatsAppWebhookEvent::class);
    }

    public function messageDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppMessageDelivery::class);
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(WhatsAppMediaFile::class);
    }
}
