<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender',
        'direction',
        'sender_type',
        'content',
        'type',
        'provider',
        'external_message_id',
        'external_sender_id',
        'external_recipient_id',
        'reply_to_message_id',
        'delivery_status',
        'metadata',
        'received_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'error_code',
        'pinned_at',
        'pinned_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'received_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_user_id');
    }

    public function orderFragments(): HasMany
    {
        return $this->hasMany(OrderFragment::class);
    }

    public function whatsappMessageDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppMessageDelivery::class);
    }

    public function aiResponseSuggestions(): HasMany
    {
        return $this->hasMany(AiResponseSuggestion::class);
    }

    public function automationEvents(): HasMany
    {
        return $this->hasMany(AutomationEvent::class);
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(WhatsAppMediaFile::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(ConversationAlert::class);
    }
}
