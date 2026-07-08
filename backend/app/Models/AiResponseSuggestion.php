<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiResponseSuggestion extends Model
{
    public const TYPE_REPLY = 'reply';

    public const TYPE_CONFIRMATION_QUESTION = 'confirmation_question';

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_REQUIRES_HUMAN_CONFIRMATION = 'requires_human_confirmation';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id',
        'conversation_id',
        'message_id',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'provider',
        'suggestion_type',
        'status',
        'prompt_summary',
        'suggested_text',
        'confidence_score',
        'requires_human_confirmation',
        'ambiguity_reason',
        'safety_notes',
        'metadata',
        'requested_at',
        'reviewed_at',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'decimal:2',
            'requires_human_confirmation' => 'boolean',
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function automationEvents(): HasMany
    {
        return $this->hasMany(AutomationEvent::class);
    }
}
