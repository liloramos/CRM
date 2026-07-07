<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationEvent extends Model
{
    public const TYPE_AI_SUGGESTION_CREATED = 'ai_suggestion_created';

    public const TYPE_AI_SUGGESTION_APPROVED = 'ai_suggestion_approved';

    public const TYPE_AI_SUGGESTION_REJECTED = 'ai_suggestion_rejected';

    public const TYPE_MANUAL_TAKEOVER = 'manual_takeover';

    public const TYPE_AUTOMATION_MODE_CHANGED = 'automation_mode_changed';

    public const TYPE_N8N_DISPATCH = 'n8n_dispatch';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'conversation_id',
        'order_id',
        'message_id',
        'ai_response_suggestion_id',
        'created_by_user_id',
        'provider',
        'event_type',
        'status',
        'requires_human_confirmation',
        'payload',
        'response_payload',
        'error_message',
        'dispatched_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'requires_human_confirmation' => 'boolean',
            'payload' => 'array',
            'response_payload' => 'array',
            'dispatched_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(AiResponseSuggestion::class, 'ai_response_suggestion_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
