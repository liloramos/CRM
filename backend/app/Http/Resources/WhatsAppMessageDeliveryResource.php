<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppMessageDeliveryResource extends JsonResource
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
            'conversation_id' => $this->conversation_id,
            'message_id' => $this->message_id,
            'provider' => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'recipient' => $this->recipient,
            'sender' => $this->sender,
            'status' => $this->status,
            'content_preview' => $this->content_preview,
            'safe_payload' => $this->safe_payload,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,
            'failed_at' => $this->failed_at,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
