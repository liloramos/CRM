<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'order_id' => $this->order_id,
            'customer_address_id' => $this->customer_address_id,
            'delivery_setting_id' => $this->delivery_setting_id,
            'quoted_by_user_id' => $this->quoted_by_user_id,
            'fulfillment_type' => $this->fulfillment_type,
            'status' => $this->status,
            'distance_km' => $this->distance_km,
            'price_per_km_cents' => $this->price_per_km_cents,
            'base_fee_cents' => $this->base_fee_cents,
            'surcharge_percent' => $this->surcharge_percent,
            'surcharge_cents' => $this->surcharge_cents,
            'delivery_fee_cents' => $this->delivery_fee_cents,
            'currency' => $this->currency,
            'calculation_mode' => $this->calculation_mode,
            'maps_provider' => $this->maps_provider,
            'external_route_id' => $this->external_route_id,
            'delivery_address_snapshot' => $this->delivery_address_snapshot,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'address_reference' => $this->address_reference,
            'delivery_notes' => $this->delivery_notes,
            'maps_metadata' => $this->maps_metadata,
            'quoted_at' => $this->quoted_at,
            'accepted_at' => $this->accepted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
