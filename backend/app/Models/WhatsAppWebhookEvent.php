<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    public const EVENT_WEBHOOK = 'webhook';

    public const EVENT_MESSAGE = 'message';

    public const EVENT_STATUS = 'status';

    protected $fillable = [
        'company_id',
        'whatsapp_account_id',
        'provider',
        'event_type',
        'provider_event_id',
        'status',
        'request_method',
        'signature_present',
        'source_ip_hash',
        'raw_payload',
        'sanitized_payload',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_present' => 'boolean',
            'raw_payload' => 'array',
            'sanitized_payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }
}
