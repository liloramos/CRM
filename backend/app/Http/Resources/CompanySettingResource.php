<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'default_attendance_mode' => $this->default_attendance_mode,
            'settings' => $this->settings,
            'onboarding_completed_at' => $this->onboarding_completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
