<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiResponseSuggestionResource extends JsonResource
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
            'message_id' => $this->message_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'provider' => $this->provider,
            'suggestion_type' => $this->suggestion_type,
            'status' => $this->status,
            'prompt_summary' => $this->prompt_summary,
            'suggested_text' => $this->suggested_text,
            'confidence_score' => $this->confidence_score,
            'requires_human_confirmation' => $this->requires_human_confirmation,
            'ambiguity_reason' => $this->ambiguity_reason,
            'safety_notes' => $this->safety_notes,
            'metadata' => $this->metadata,
            'requested_at' => $this->requested_at,
            'reviewed_at' => $this->reviewed_at,
            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'automation_events' => AutomationEventResource::collection($this->whenLoaded('automationEvents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
