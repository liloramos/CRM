<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentProofResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'order_id' => $this->order_id,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'source_channel' => $this->source_channel,
            'storage_disk' => $this->storage_disk,
            'file_path' => $this->file_path,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'amount_cents' => $this->amount_cents,
            'status' => $this->status,
            'received_at' => $this->received_at,
            'review_notes' => $this->review_notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
