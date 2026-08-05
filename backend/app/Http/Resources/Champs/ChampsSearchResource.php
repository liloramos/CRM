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
            'minimum_score' => $this->minimum_score,
            'status' => $this->status,
            'total_discovered' => $this->total_discovered,
            'total_saved' => $this->total_saved,
            'total_qualified' => $this->total_qualified,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'results' => ChampsSearchResultResource::collection($this->whenLoaded('results')),
        ];
    }
}
