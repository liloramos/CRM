<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobEventResource extends JsonResource
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
            'print_job_id' => $this->print_job_id,
            'user_id' => $this->user_id,
            'event_type' => $this->event_type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
