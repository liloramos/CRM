<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_type' => $this->product_type,
            'menu_rule_code' => $this->menu_rule_code,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unit_price_cents,
            'options_total_cents' => $this->options_total_cents,
            'total_price_cents' => $this->total_price_cents,
            'currency' => $this->currency,
            'item_notes' => $this->item_notes,
            'beneficiary_name' => $this->beneficiary_name,
            'beneficiary_notes' => $this->beneficiary_notes,
            'preferences' => $this->preferences,
            'restrictions' => $this->restrictions,
            'removed_ingredients' => $this->removed_ingredients,
            'selected_components' => $this->selected_components,
            'substitution_notes' => $this->substitution_notes,
            'sort_order' => $this->sort_order,
            'options' => OrderItemOptionResource::collection($this->whenLoaded('options')),
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
