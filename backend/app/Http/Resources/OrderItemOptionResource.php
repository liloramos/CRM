<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_item_id' => $this->order_item_id,
            'product_option_id' => $this->product_option_id,
            'name' => $this->name,
            'option_type' => $this->option_type,
            'group_code' => $this->group_code,
            'quantity' => $this->quantity,
            'price_delta_cents' => $this->price_delta_cents,
            'total_price_cents' => $this->total_price_cents,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
