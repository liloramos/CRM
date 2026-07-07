<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'conversation_id' => $this->conversation_id,
            'order_id' => $this->order_id,
            'message_id' => $this->message_id,
            'ai_response_suggestion_id' => $this->ai_response_suggestion_id,
            'created_by_user_id' => $this->created_by_user_id,
            'provider' => $this->provider,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'requires_human_confirmation' => $this->requires_human_confirmation,
            'payload' => $this->payload,
            'response_payload' => $this->response_payload,
            'error_message' => $this->error_message,
            'dispatched_at' => $this->dispatched_at,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
