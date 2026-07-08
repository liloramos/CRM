<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const METHOD_PIX = 'pix';

    public const METHOD_CASH = 'cash';

    public const METHOD_DEBIT_CARD = 'debit_card';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_CUSTOMER_CREDIT = 'customer_credit';

    public const METHOD_MIXED = 'mixed';

    public const METHOD_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_PROOF = 'awaiting_proof';

    public const STATUS_PROOF_RECEIVED = 'proof_received';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const ORDER_STATUS_UNPAID = 'unpaid';

    public const ORDER_STATUS_PENDING = 'pending';

    public const ORDER_STATUS_PARTIAL = 'partial';

    public const ORDER_STATUS_PAID = 'paid';

    public const ORDER_STATUS_OVERPAID = 'overpaid';

    public const ORDER_STATUS_REJECTED = 'rejected';

    public const PROVIDER_MANUAL = 'manual';

    public const OVERPAYMENT_PENDING_REVIEW = 'pending_review';

    public const OVERPAYMENT_KEEP_AS_CREDIT = 'keep_as_credit';

    public const OVERPAYMENT_REFUND = 'refund';

    /**
     * @var list<string>
     */
    public const METHODS = [
        self::METHOD_PIX,
        self::METHOD_CASH,
        self::METHOD_DEBIT_CARD,
        self::METHOD_CREDIT_CARD,
        self::METHOD_CUSTOMER_CREDIT,
        self::METHOD_OTHER,
    ];

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_PROOF,
        self::STATUS_PROOF_RECEIVED,
        self::STATUS_CONFIRMED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'company_id',
        'order_id',
        'customer_id',
        'created_by_user_id',
        'confirmed_by_user_id',
        'rejected_by_user_id',
        'method',
        'provider',
        'status',
        'amount_cents',
        'confirmed_amount_cents',
        'amount_due_after_payment_cents',
        'currency',
        'external_reference',
        'overpayment_action',
        'paid_at',
        'confirmed_at',
        'rejected_at',
        'rejection_reason',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'confirmed_amount_cents' => 'integer',
            'amount_due_after_payment_cents' => 'integer',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }
}
