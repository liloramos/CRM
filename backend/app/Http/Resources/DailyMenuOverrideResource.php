<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyMenuOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'availability_date' => $this->availability_date,
            'status' => $this->status,
            'reason' => $this->reason,
            'replacement_product_id' => $this->replacement_product_id,
            'marked_by_user_id' => $this->marked_by_user_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'replacement_product' => new ProductResource($this->whenLoaded('replacementProduct')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
