<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppMediaFileResource extends JsonResource
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
            'message_id' => $this->message_id,
            'whatsapp_webhook_event_id' => $this->whatsapp_webhook_event_id,
            'provider' => $this->provider,
            'provider_media_id' => $this->provider_media_id,
            'media_type' => $this->media_type,
            'mime_type' => $this->mime_type,
            'sha256' => $this->sha256,
            'storage_disk' => $this->storage_disk,
            'file_path' => $this->file_path,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
