<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends Model
{
    public const TYPE_ORDER_TICKET = 'order_ticket';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PRINTING = 'printing';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REPRINT_REQUESTED = 'reprint_requested';

    public const STATUS_PRINTER_UNAVAILABLE = 'printer_unavailable';

    public const STATUS_MANUAL_CONFIRMED = 'manual_confirmed';

    public const STATUS_WAIVED = 'waived';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PREVIEWED,
        self::STATUS_QUEUED,
        self::STATUS_PRINTING,
        self::STATUS_PRINTED,
        self::STATUS_FAILED,
        self::STATUS_REPRINT_REQUESTED,
        self::STATUS_PRINTER_UNAVAILABLE,
        self::STATUS_MANUAL_CONFIRMED,
        self::STATUS_WAIVED,
    ];

    protected $fillable = [
        'company_id',
        'order_id',
        'receipt_template_id',
        'printer_setting_id',
        'requested_by_user_id',
        'printed_by_user_id',
        'parent_print_job_id',
        'job_type',
        'target_audience',
        'status',
        'copy_number',
        'is_reprint',
        'preview_url',
        'html_content',
        'text_content',
        'rendered_payload',
        'error_message',
        'requested_at',
        'previewed_at',
        'printing_started_at',
        'printed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'copy_number' => 'integer',
            'is_reprint' => 'boolean',
            'rendered_payload' => 'array',
            'requested_at' => 'datetime',
            'previewed_at' => 'datetime',
            'printing_started_at' => 'datetime',
            'printed_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function receiptTemplate(): BelongsTo
    {
        return $this->belongsTo(ReceiptTemplate::class);
    }

    public function printerSetting(): BelongsTo
    {
        return $this->belongsTo(PrinterSetting::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by_user_id');
    }

    public function parentPrintJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_print_job_id');
    }

    public function reprints(): HasMany
    {
        return $this->hasMany(self::class, 'parent_print_job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class);
    }
}
