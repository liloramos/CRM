<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerCreditMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'customer_id' => $this->customer_id,
            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'created_by_user_id' => $this->created_by_user_id,
            'type' => $this->type,
            'direction' => $this->direction,
            'amount_cents' => $this->amount_cents,
            'balance_before_cents' => $this->balance_before_cents,
            'balance_after_cents' => $this->balance_after_cents,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
