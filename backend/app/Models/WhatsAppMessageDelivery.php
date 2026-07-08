<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessageDelivery extends Model
{
    protected $table = 'whatsapp_message_deliveries';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'whatsapp_account_id',
        'conversation_id',
        'message_id',
        'provider',
        'provider_message_id',
        'direction',
        'message_type',
        'recipient',
        'sender',
        'status',
        'content_preview',
        'safe_payload',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'safe_payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
