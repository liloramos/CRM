<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFragment extends Model
{
    protected $fillable = [
        'order_id',
        'conversation_id',
        'message_id',
        'created_by_user_id',
        'source_channel',
        'fragment_type',
        'content_summary',
        'parsed_payload',
        'is_resolved',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_payload' => 'array',
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
