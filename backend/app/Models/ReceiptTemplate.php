<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptTemplate extends Model
{
    public const TYPE_ORDER_TICKET = 'order_ticket';

    public const TARGET_KITCHEN = 'kitchen';

    public const TARGET_CASHIER = 'cashier';

    public const TARGET_DELIVERY = 'delivery';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'template_type',
        'target_audience',
        'view_name',
        'width_chars',
        'includes_financials',
        'is_default',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'width_chars' => 'integer',
            'includes_financials' => 'boolean',
            'is_default' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
