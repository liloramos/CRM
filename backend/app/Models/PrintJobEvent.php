<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJobEvent extends Model
{
    public const EVENT_TICKET_GENERATED = 'ticket_generated';

    public const EVENT_PRINT_STARTED = 'print_started';

    public const EVENT_PRINTED = 'printed';

    public const EVENT_PRINT_FAILED = 'print_failed';

    public const EVENT_REPRINT_REQUESTED = 'reprint_requested';

    public const EVENT_MANUAL_CONFIRMED = 'manual_print_confirmed';

    public const EVENT_PRINT_WAIVED = 'print_waived';

    public const EVENT_ADVANCED_WITHOUT_PRINT = 'advanced_without_print';

    protected $fillable = [
        'company_id',
        'order_id',
        'print_job_id',
        'user_id',
        'event_type',
        'from_status',
        'to_status',
        'message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
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

    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
