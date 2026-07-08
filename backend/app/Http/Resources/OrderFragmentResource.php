<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderFragmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'conversation_id' => $this->conversation_id,
            'message_id' => $this->message_id,
            'created_by_user_id' => $this->created_by_user_id,
            'source_channel' => $this->source_channel,
            'fragment_type' => $this->fragment_type,
            'content_summary' => $this->content_summary,
            'parsed_payload' => $this->parsed_payload,
            'is_resolved' => $this->is_resolved,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
