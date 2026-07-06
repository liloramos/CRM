<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeeklyMenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weekly_menu_id' => $this->weekly_menu_id,
            'product_id' => $this->product_id,
            'service_day' => $this->service_day,
            'is_available_by_default' => $this->is_available_by_default,
            'notes' => $this->notes,
            'display_order' => $this->display_order,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
