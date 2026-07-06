<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperatingHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'weekday' => $this->weekday,
            'opens_at' => $this->opens_at,
            'closes_at' => $this->closes_at,
            'is_open' => $this->is_open,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
