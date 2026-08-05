<?php

namespace App\Http\Resources\Champs;

use App\Models\ChampsSearchResult;
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
            : null;

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
