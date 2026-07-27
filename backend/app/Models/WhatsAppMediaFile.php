<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMediaFile extends Model
{
    protected $table = 'whatsapp_media_files';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_STORED = 'stored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'whatsapp_account_id',
        'message_id',
        'whatsapp_webhook_event_id',
        'provider',
        'provider_media_id',
        'media_type',
        'mime_type',
        'sha256',
        'original_filename',
        'size_bytes',
        'checksum',
        'storage_disk',
        'file_path',
        'url_expires_at',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'url_expires_at' => 'datetime',
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppWebhookEvent::class, 'whatsapp_webhook_event_id');
    }
}
