<?php

namespace App\Http\Resources\Champs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChampsSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'search_id' => $this->search_id,
            'lead_id' => $this->lead_id,
            'score' => $this->score,
            'classification' => $this->classification,
            'reasons' => $this->reasons,
            'criteria' => $this->criteria,
            'qualified' => $this->qualified,
            'position' => $this->position,
            'lead' => new ChampsLeadResource($this->whenLoaded('lead')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
