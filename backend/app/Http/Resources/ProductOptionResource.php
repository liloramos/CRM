<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductOptionResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'option_type' => $this->option_type,
            'group_code' => $this->group_code,
            'price_delta_cents' => $this->price_delta_cents,
            'max_quantity' => $this->max_quantity,
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
            'rules' => $this->rules,
            'display_order' => $this->display_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
