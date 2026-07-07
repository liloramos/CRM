<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'provider' => $this->provider,
            'name' => $this->name,
            'phone_number_id' => $this->phone_number_id,
            'business_account_id' => $this->business_account_id,
            'display_phone_number' => $this->display_phone_number,
            'status' => $this->status,
            'connection_status_message' => $this->connection_status_message,
            'is_default' => $this->is_default,
            'webhook_verified_at' => $this->webhook_verified_at,
            'last_webhook_at' => $this->last_webhook_at,
            'connected_at' => $this->connected_at,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
