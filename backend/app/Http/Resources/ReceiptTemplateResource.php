<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'template_type' => $this->template_type,
            'target_audience' => $this->target_audience,
            'view_name' => $this->view_name,
            'width_chars' => $this->width_chars,
            'includes_financials' => $this->includes_financials,
            'is_default' => $this->is_default,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
