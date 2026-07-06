<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_AWAITING_CUSTOMER_CONFIRMATION = 'awaiting_customer_confirmation';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_AWAITING_PAYMENT_PROOF = 'awaiting_payment_proof';

    public const STATUS_PAYMENT_PROOF_RECEIVED = 'payment_proof_received';

    public const STATUS_PAYMENT_CONFIRMED = 'payment_confirmed';

    public const STATUS_PAYMENT_REJECTED = 'payment_rejected';

    public const STATUS_READY_TO_PRINT = 'ready_to_print';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_IN_PREPARATION = 'in_preparation';

    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_COUNTER = 'counter';

    public const CHANNEL_MANUAL = 'manual';

    public const CHANNEL_PHONE = 'phone';

    public const CHANNEL_OTHER = 'other';

    public const FULFILLMENT_PICKUP = 'pickup';

    public const FULFILLMENT_DELIVERY = 'delivery';

    public const FULFILLMENT_COUNTER = 'counter';

    public const FULFILLMENT_DINE_IN = 'dine_in';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_URGENT = 'urgent';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        self::STATUS_CONFIRMED,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_AWAITING_PAYMENT_PROOF,
        self::STATUS_PAYMENT_PROOF_RECEIVED,
        self::STATUS_PAYMENT_CONFIRMED,
        self::STATUS_PAYMENT_REJECTED,
        self::STATUS_READY_TO_PRINT,
        self::STATUS_PRINTED,
        self::STATUS_IN_PREPARATION,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_OUT_FOR_DELIVERY,
        self::STATUS_FINISHED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var list<string>
     */
    public const LOCKED_STATUSES = [
        self::STATUS_PRINTED,
        self::STATUS_IN_PREPARATION,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_OUT_FOR_DELIVERY,
        self::STATUS_FINISHED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'company_id',
        'payer_customer_id',
        'conversation_id',
        'created_by_user_id',
        'recurring_order_reference_id',
        'order_date',
        'daily_sequence',
        'code',
        'status',
        'origin_channel',
        'entry_mode',
        'fulfillment_type',
        'priority',
        'is_manual',
        'is_fragmented',
        'customer_confirmation_required',
        'human_review_required',
        'recurrence_requested',
        'recurrence_note',
        'general_notes',
        'kitchen_notes',
        'pickup_person_name',
        'pickup_person_phone',
        'pickup_authorized_by',
        'pickup_notes',
        'payment_method',
        'payment_status',
        'subtotal_cents',
        'adjustments_cents',
        'total_cents',
        'amount_paid_cents',
        'amount_due_cents',
        'credit_used_cents',
        'credit_generated_cents',
        'currency',
        'last_payment_at',
        'payment_confirmed_at',
        'confirmed_at',
        'cancelled_at',
        'finished_at',
        'editing_locked_at',
        'editing_locked_reason',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'daily_sequence' => 'integer',
            'is_manual' => 'boolean',
            'is_fragmented' => 'boolean',
            'customer_confirmation_required' => 'boolean',
            'human_review_required' => 'boolean',
            'recurrence_requested' => 'boolean',
            'subtotal_cents' => 'integer',
            'adjustments_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'amount_due_cents' => 'integer',
            'credit_used_cents' => 'integer',
            'credit_generated_cents' => 'integer',
            'last_payment_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'finished_at' => 'datetime',
            'editing_locked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payerCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'payer_customer_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function recurringOrderReference(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurring_order_reference_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function fragments(): HasMany
    {
        return $this->hasMany(OrderFragment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }

    public function canBeEdited(): bool
    {
        return $this->editing_locked_at === null
            && ! in_array($this->status, self::LOCKED_STATUSES, true);
    }
}
