<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliverySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'is_active' => $this->is_active,
            'calculation_mode' => $this->calculation_mode,
            'price_per_km_cents' => $this->price_per_km_cents,
            'surcharge_percent' => $this->surcharge_percent,
            'minimum_fee_cents' => $this->minimum_fee_cents,
            'maximum_distance_km' => $this->maximum_distance_km,
            'rounding_mode' => $this->rounding_mode,
            'maps_provider' => $this->maps_provider,
            'provider_options' => $this->provider_options,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
