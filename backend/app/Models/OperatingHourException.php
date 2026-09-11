<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingHourException extends Model
{
    protected $fillable = [
        'company_id',
        'date',
        'is_open',
        'opens_at',
        'closes_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_open' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
