<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'payer_customer_id' => $this->payer_customer_id,
            'conversation_id' => $this->conversation_id,
            'created_by_user_id' => $this->created_by_user_id,
            'recurring_order_reference_id' => $this->recurring_order_reference_id,
            'order_date' => $this->order_date,
            'daily_sequence' => $this->daily_sequence,
            'code' => $this->code,
            'status' => $this->status,
            'origin_channel' => $this->origin_channel,
            'entry_mode' => $this->entry_mode,
            'fulfillment_type' => $this->fulfillment_type,
            'priority' => $this->priority,
            'is_manual' => $this->is_manual,
            'is_fragmented' => $this->is_fragmented,
            'customer_confirmation_required' => $this->customer_confirmation_required,
            'human_review_required' => $this->human_review_required,
            'recurrence_requested' => $this->recurrence_requested,
            'recurrence_note' => $this->recurrence_note,
            'general_notes' => $this->general_notes,
            'kitchen_notes' => $this->kitchen_notes,
            'pickup_person_name' => $this->pickup_person_name,
            'pickup_person_phone' => $this->pickup_person_phone,
            'pickup_authorized_by' => $this->pickup_authorized_by,
            'pickup_notes' => $this->pickup_notes,
            'subtotal_cents' => $this->subtotal_cents,
            'adjustments_cents' => $this->adjustments_cents,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'confirmed_at' => $this->confirmed_at,
            'cancelled_at' => $this->cancelled_at,
            'finished_at' => $this->finished_at,
            'editing_locked_at' => $this->editing_locked_at,
            'editing_locked_reason' => $this->editing_locked_reason,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'fragments' => OrderFragmentResource::collection($this->whenLoaded('fragments')),
            'status_histories' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'payer_customer' => new CustomerResource($this->whenLoaded('payerCustomer')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
