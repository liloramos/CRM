<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'customer_id' => $this->customer_id,
            'created_by_user_id' => $this->created_by_user_id,
            'confirmed_by_user_id' => $this->confirmed_by_user_id,
            'rejected_by_user_id' => $this->rejected_by_user_id,
            'method' => $this->method,
            'provider' => $this->provider,
            'status' => $this->status,
            'amount_cents' => $this->amount_cents,
            'confirmed_amount_cents' => $this->confirmed_amount_cents,
            'amount_due_after_payment_cents' => $this->amount_due_after_payment_cents,
            'currency' => $this->currency,
            'external_reference' => $this->external_reference,
            'overpayment_action' => $this->overpayment_action,
            'paid_at' => $this->paid_at,
            'confirmed_at' => $this->confirmed_at,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'proofs' => PaymentProofResource::collection($this->whenLoaded('proofs')),
            'credit_movements' => CustomerCreditMovementResource::collection($this->whenLoaded('creditMovements')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
