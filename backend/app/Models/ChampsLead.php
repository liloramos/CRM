<?php

namespace App\Models;

use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChampsLead extends Model
{
    protected $fillable = [
        'company_id',
        'provider',
        'external_id',
        'name',
        'formatted_address',
        'city',
        'state',
        'phone',
        'email',
        'website',
        'rating',
        'user_rating_count',
        'business_status',
        'instagram_username',
        'instagram_profile_url',
        'instagram_followers_count',
        'instagram_media_count',
        'instagram_is_professional',
        'source_data',
        'is_favorite',
        'pipeline_stage',
        'priority',
        'assigned_user_id',
        'next_follow_up_at',
        'last_contacted_at',
        'commercial_notes',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'user_rating_count' => 'integer',
            'instagram_followers_count' => 'integer',
            'instagram_media_count' => 'integer',
            'instagram_is_professional' => 'boolean',
            'source_data' => 'array',
            'is_favorite' => 'boolean',
            'pipeline_stage' => ChampsLeadStage::class,
            'priority' => ChampsLeadPriority::class,
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('is_favorite', true)
                ->orWhere('pipeline_stage', '!=', ChampsLeadStage::New->value)
                ->orWhereNotNull('next_follow_up_at');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ChampsLeadActivity::class, 'lead_id');
    }

    public function searchResults(): HasMany
    {
        return $this->hasMany(ChampsSearchResult::class, 'lead_id');
    }

    public function latestSearchResult(): HasOne
    {
        return $this->hasOne(ChampsSearchResult::class, 'lead_id')->latestOfMany();
    }

    public function searches(): BelongsToMany
    {
        return $this->belongsToMany(
            ChampsSearch::class,
            'champs_search_results',
            'lead_id',
            'search_id',
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
