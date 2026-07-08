<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_id',
        'status',
        'timezone',
        'locale',
        'currency',
        'default_attendance_mode',
        'settings',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
