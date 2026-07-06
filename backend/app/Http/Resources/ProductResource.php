<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'product_type' => $this->product_type,
            'menu_rule_code' => $this->menu_rule_code,
            'description' => $this->description,
            'base_price_cents' => $this->base_price_cents,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'is_available_by_default' => $this->is_available_by_default,
            'allows_item_notes' => $this->allows_item_notes,
            'notes_hint' => $this->notes_hint,
            'composition_rules' => $this->composition_rules,
            'metadata' => $this->metadata,
            'display_order' => $this->display_order,
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'options' => ProductOptionResource::collection($this->whenLoaded('options')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
