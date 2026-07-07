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
        'content',
        'type',
        'provider',
        'external_message_id',
        'external_sender_id',
        'external_recipient_id',
        'delivery_status',
        'metadata',
        'received_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'received_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function orderFragments(): HasMany
    {
        return $this->hasMany(OrderFragment::class);
    }

    public function whatsappMessageDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppMessageDelivery::class);
    }
}
