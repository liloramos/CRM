<?php

namespace App\Http\Resources;

use App\Models\DailyMenuOptionOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dailyOverride = $this->relationLoaded('dailyMenuOptionOverrides')
            ? $this->dailyMenuOptionOverrides->first()
            : null;

        $dailyStatus = $dailyOverride?->status ?? DailyMenuOptionOverride::STATUS_AVAILABLE;

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'option_type' => $this->option_type,
            'group_code' => $this->group_code,
            'price_delta_cents' => $this->price_delta_cents,
            'max_quantity' => $this->max_quantity,
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
            'available_today' => (bool) $this->is_active && $dailyStatus !== DailyMenuOptionOverride::STATUS_UNAVAILABLE,
            'daily_status' => $dailyStatus,
            'daily_reason' => $dailyOverride?->reason,
            'group_label' => $this->groupLabel($this->group_code),
            'rules' => $this->rules,
            'display_order' => $this->display_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function groupLabel(?string $groupCode): string
    {
        return match ($groupCode) {
            'base', 'bases', 'guarnicoes' => 'Bases/guarnicoes',
            'salada' => 'Saladas',
            'carne', 'bife' => 'Carnes',
            'bebidas' => 'Bebidas',
            'adicionais' => 'Adicionais',
            default => 'Componentes',
        };
    }
}
