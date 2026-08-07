<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyIdentityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('setting');
        $user = $request->user();

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'responsible_name' => $this->responsible_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'timezone' => $this->setting?->timezone ?? 'America/Sao_Paulo',
            'logo_url' => $this->resource->logoUrl(),
            'slug' => $this->slug,
            'can_manage' => $user?->company_id === $this->id
                && $user->hasPermissionTo('settings.manage'),
        ];
    }
}
