<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_WHATSAPP = 'whatsapp';

    protected $fillable = [
        'payment_id',
        'order_id',
        'uploaded_by_user_id',
        'source_channel',
        'storage_disk',
        'file_path',
        'original_filename',
        'mime_type',
        'amount_cents',
        'status',
        'received_at',
        'review_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
