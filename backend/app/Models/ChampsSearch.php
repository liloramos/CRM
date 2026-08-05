<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChampsSearch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIALLY_COMPLETED = 'partially_completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_PARTIALLY_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'niche',
        'city',
        'state',
        'requested_limit',
        'provider',
        'minimum_score',
        'status',
        'total_discovered',
        'total_saved',
        'total_qualified',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_limit' => 'integer',
            'minimum_score' => 'integer',
            'total_discovered' => 'integer',
            'total_saved' => 'integer',
            'total_qualified' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ChampsSearchResult::class, 'search_id');
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(
            ChampsLead::class,
            'champs_search_results',
            'search_id',
            'lead_id',
        )->withPivot([
            'company_id',
            'score',
            'classification',
            'reasons',
            'criteria',
            'qualified',
            'position',
        ])
            ->withTimestamps();
    }
}
