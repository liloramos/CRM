<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'display_name' => $this->display_name,
            'legal_name' => $this->legal_name,
            'document' => $this->document,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website' => $this->website,
            'description' => $this->description,
            'address' => [
                'line' => $this->address_line,
                'number' => $this->address_number,
                'complement' => $this->address_complement,
                'district' => $this->district,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
