<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChampsSearchResult extends Model
{
    protected $fillable = [
        'company_id',
        'search_id',
        'lead_id',
        'score',
        'classification',
        'reasons',
        'criteria',
        'qualified',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'reasons' => 'array',
            'criteria' => 'array',
            'qualified' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(ChampsSearch::class, 'search_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(ChampsLead::class, 'lead_id');
    }
}
