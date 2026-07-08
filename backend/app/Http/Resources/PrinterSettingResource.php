<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'printer_model' => $this->printer_model,
            'print_mode' => $this->print_mode,
            'connection_type' => $this->connection_type,
            'status' => $this->status,
            'paper_width_mm' => $this->paper_width_mm,
            'is_default' => $this->is_default,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
