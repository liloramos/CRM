<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAutomationSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'provider' => $this->provider,
            'default_mode' => $this->default_mode,
            'automation_enabled' => $this->automation_enabled,
            'allow_auto_send' => $this->allow_auto_send,
            'require_human_confirmation_for_ambiguous' => $this->require_human_confirmation_for_ambiguous,
            'require_human_confirmation_for_payments' => $this->require_human_confirmation_for_payments,
            'n8n_webhook_path_present' => $this->n8n_webhook_path !== null && $this->n8n_webhook_path !== '',
            'status' => $this->status,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
