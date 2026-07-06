<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditMovement extends Model
{
    public const TYPE_CREDIT_GENERATED = 'credito_gerado';

    public const TYPE_CREDIT_USED = 'credito_utilizado';

    public const TYPE_PENDING_DEBIT = 'debito_pendente';

    public const TYPE_MANUAL_ADJUSTMENT = 'ajuste_manual';

    public const TYPE_REFUND = 'devolucao';

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_CREDIT_GENERATED,
        self::TYPE_CREDIT_USED,
        self::TYPE_PENDING_DEBIT,
        self::TYPE_MANUAL_ADJUSTMENT,
        self::TYPE_REFUND,
    ];

    protected $fillable = [
        'company_id',
        'customer_id',
        'order_id',
        'payment_id',
        'created_by_user_id',
        'type',
        'direction',
        'amount_cents',
        'balance_before_cents',
        'balance_after_cents',
        'currency',
        'reason',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'balance_before_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'metadata' => 'array',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
