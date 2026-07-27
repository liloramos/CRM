<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    public const AUTOMATION_MODE_ASSISTED = 'assisted';

    public const AUTOMATION_MODE_AUTOMATIC = 'automatic';

    public const AUTOMATION_MODE_MANUAL = 'manual';

    public const AUTOMATION_STATUS_ACTIVE = 'active';

    public const AUTOMATION_STATUS_MANUAL_TAKEOVER = 'manual_takeover';

    public const AUTOMATION_STATUS_PAUSED = 'paused';

    public const AUTOMATION_STATUS_FALLBACK_REQUIRED = 'fallback_required';

    /**
     * @var list<string>
     */
    public const AUTOMATION_MODES = [
        self::AUTOMATION_MODE_ASSISTED,
        self::AUTOMATION_MODE_AUTOMATIC,
        self::AUTOMATION_MODE_MANUAL,
    ];

    protected $fillable = [
        'company_id',
        'customer_id',
        'active_order_id',
        'channel',
        'whatsapp_identifier',
        'whatsapp_profile_name',
        'status',
        'automation_mode',
        'automation_status',
        'assigned_user_id',
        'unread_count',
        'automation_version',
        'human_review_required',
        'manual_takeover_reason',
        'manual_takeover_at',
        'manual_takeover_by_user_id',
        'automation_paused_until',
        'last_ai_suggestion_at',
        'ai_context_summary',
        'last_message_at',
        'last_customer_message_at',
        'last_business_message_at',
        'ai_paused_at',
        'handoff_reason',
        'started_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'human_review_required' => 'boolean',
            'unread_count' => 'integer',
            'automation_version' => 'integer',
            'manual_takeover_at' => 'datetime',
            'automation_paused_until' => 'datetime',
            'last_ai_suggestion_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'last_business_message_at' => 'datetime',
            'ai_paused_at' => 'datetime',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'active_order_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function manualTakeoverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_takeover_by_user_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
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

    public function alerts(): HasMany
    {
        return $this->hasMany(ConversationAlert::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
