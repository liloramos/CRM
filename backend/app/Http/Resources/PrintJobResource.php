<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobResource extends JsonResource
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
            'receipt_template_id' => $this->receipt_template_id,
            'printer_setting_id' => $this->printer_setting_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'printed_by_user_id' => $this->printed_by_user_id,
            'parent_print_job_id' => $this->parent_print_job_id,
            'job_type' => $this->job_type,
            'target_audience' => $this->target_audience,
            'status' => $this->status,
            'copy_number' => $this->copy_number,
            'is_reprint' => $this->is_reprint,
            'preview_url' => $this->preview_url,
            'rendered_payload' => $this->rendered_payload,
            'error_message' => $this->error_message,
            'requested_at' => $this->requested_at,
            'previewed_at' => $this->previewed_at,
            'printing_started_at' => $this->printing_started_at,
            'printed_at' => $this->printed_at,
            'failed_at' => $this->failed_at,
            'receipt_template' => new ReceiptTemplateResource($this->whenLoaded('receiptTemplate')),
            'printer_setting' => new PrinterSettingResource($this->whenLoaded('printerSetting')),
            'events' => PrintJobEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
