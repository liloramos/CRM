<?php

namespace App\Http\Resources\Champs;

use App\Champs\Enums\ChampsLeadActivityType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChampsLeadActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource->relationLoaded('user')
            ? $this->resource->getRelation('user')
            : null;
        $type = $this->type;

        return [
            'id' => $this->id,
            'type' => $type instanceof ChampsLeadActivityType ? $type->value : $type,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'user' => $user instanceof User ? [
                'id' => $user->id,
                'name' => $user->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
