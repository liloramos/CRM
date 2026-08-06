<?php

namespace App\Http\Resources\Champs;

use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use App\Models\ChampsSearchResult;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChampsLeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentResult = $this->resource->relationLoaded('currentSearchResult')
            ? $this->resource->getRelation('currentSearchResult')
            : ($this->resource->relationLoaded('latestSearchResult')
                ? $this->resource->getRelation('latestSearchResult')
                : null);
        $assignedUser = $this->resource->relationLoaded('assignedUser')
            ? $this->resource->getRelation('assignedUser')
            : null;
        $stage = $this->pipeline_stage;
        $priority = $this->priority;

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'formatted_address' => $this->formatted_address,
            'city' => $this->city,
            'state' => $this->state,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'user_rating_count' => $this->user_rating_count,
            'business_status' => $this->business_status,
            'instagram_username' => $this->instagram_username,
            'instagram_profile_url' => $this->instagram_profile_url,
            'instagram_followers_count' => $this->instagram_followers_count,
            'instagram_media_count' => $this->instagram_media_count,
            'instagram_is_professional' => $this->instagram_is_professional,
            'is_favorite' => $this->is_favorite,
            'pipeline_stage' => $stage instanceof ChampsLeadStage ? $stage->value : $stage,
            'priority' => $priority instanceof ChampsLeadPriority ? $priority->value : $priority,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $assignedUser instanceof User ? [
                'id' => $assignedUser->id,
                'name' => $assignedUser->name,
            ] : null,
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'commercial_notes' => $this->commercial_notes,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'activities_count' => $this->when(isset($this->activities_count), $this->activities_count),
            'score' => $this->when(
                $currentResult instanceof ChampsSearchResult,
                $currentResult?->score,
            ),
            'classification' => $this->when(
                $currentResult instanceof ChampsSearchResult,
                $currentResult?->classification,
            ),
            'reasons' => $this->when(
                $currentResult instanceof ChampsSearchResult,
                $currentResult?->reasons,
            ),
            'criteria' => $this->when(
                $currentResult instanceof ChampsSearchResult,
                $currentResult?->criteria,
            ),
            'qualified' => $this->when(
                $currentResult instanceof ChampsSearchResult,
                $currentResult?->qualified,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
