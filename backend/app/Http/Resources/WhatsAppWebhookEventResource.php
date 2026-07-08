<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppWebhookEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'provider' => $this->provider,
            'event_type' => $this->event_type,
            'provider_event_id' => $this->provider_event_id,
            'status' => $this->status,
            'request_method' => $this->request_method,
            'signature_present' => $this->signature_present,
            'source_ip_hash' => $this->source_ip_hash,
            'sanitized_payload' => $this->sanitized_payload,
            'error_message' => $this->error_message,
            'received_at' => $this->received_at,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
