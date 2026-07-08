<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingHour extends Model
{
    protected $fillable = [
        'company_id',
        'weekday',
        'opens_at',
        'closes_at',
        'is_open',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
