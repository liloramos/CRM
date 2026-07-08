<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrinterSetting extends Model
{
    public const PRINT_MODE_BROWSER_HTML = 'browser_html';

    public const CONNECTION_BROWSER_DRIVER = 'browser_driver';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'company_id',
        'name',
        'printer_model',
        'print_mode',
        'connection_type',
        'status',
        'paper_width_mm',
        'is_default',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'paper_width_mm' => 'integer',
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
