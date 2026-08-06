<?php

namespace App\Http\Resources\Champs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChampsSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'niche' => $this->niche,
            'city' => $this->city,
            'state' => $this->state,
            'requested_limit' => $this->requested_limit,
            'provider' => $this->provider,
            'search_fingerprint' => $this->search_fingerprint,
            'exclude_seen' => $this->exclude_seen,
            'minimum_score' => $this->minimum_score,
            'status' => $this->status,
            'total_discovered' => $this->total_discovered,
            'total_saved' => $this->total_saved,
            'total_qualified' => $this->total_qualified,
            'total_scanned' => $this->total_scanned,
            'total_skipped_seen' => $this->total_skipped_seen,
            'error_message' => $this->error_message,
            'message' => $this->status === $this->resource::STATUS_COMPLETED
                && $this->exclude_seen
                && $this->total_saved === 0
                    ? $this->resource::NO_NEW_RESULTS_MESSAGE
                    : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'results' => ChampsSearchResultResource::collection($this->whenLoaded('results')),
        ];
    }
}
