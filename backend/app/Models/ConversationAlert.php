<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAlert extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const TYPE_NEW_CONVERSATION = 'new_conversation';

    public const TYPE_UNREAD_MESSAGE = 'unread_message';

    public const TYPE_HUMAN_REQUESTED = 'human_requested';

    public const TYPE_LOW_CONFIDENCE_AI = 'low_confidence_ai';

    public const TYPE_PAYMENT_PROOF_RECEIVED = 'payment_proof_received';

    public const TYPE_PAYMENT_REJECTED = 'payment_rejected';

    public const TYPE_PAYMENT_EVIDENCE_REJECTED = 'payment_evidence_rejected';

    public const TYPE_MESSAGE_SEND_FAILED = 'message_send_failed';

    protected $fillable = [
        'company_id',
        'conversation_id',
        'order_id',
        'message_id',
        'payment_id',
        'payment_proof_id',
        'type',
        'severity',
        'title',
        'message',
        'deduplication_key',
        'status',
        'assigned_user_id',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'resolved_at',
        'resolved_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
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

    public function messageModel(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function paymentProof(): BelongsTo
    {
        return $this->belongsTo(PaymentProof::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
