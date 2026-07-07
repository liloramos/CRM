<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'profile' => new RestaurantProfileResource($this->whenLoaded('restaurantProfile')),
            'setting' => new CompanySettingResource($this->whenLoaded('setting')),
            'delivery_setting' => new DeliverySettingResource($this->whenLoaded('deliverySetting')),
            'printer_settings' => PrinterSettingResource::collection($this->whenLoaded('printerSettings')),
            'receipt_templates' => ReceiptTemplateResource::collection($this->whenLoaded('receiptTemplates')),
            'whatsapp_accounts' => WhatsAppAccountResource::collection($this->whenLoaded('whatsappAccounts')),
            'operating_hours' => OperatingHourResource::collection($this->whenLoaded('operatingHours')),
            'customer_addresses' => CustomerAddressResource::collection($this->whenLoaded('customerAddresses')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
