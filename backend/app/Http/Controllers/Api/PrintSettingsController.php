<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\PrinterSetting;
use App\Models\PrintJob;
use App\Models\ReceiptTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintSettingsController extends Controller
{
    use ResolvesOperationalCompany;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->data($this->resolveCompany($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $attributes = $request->validate([
            'printer_name' => ['required', 'string', 'max:120'],
            'paper_width_mm' => ['required', 'integer', 'between:40,120'],
            'receipt_template_id' => ['nullable', 'integer'],
        ]);

        $printer = $company->printerSettings()
            ->where('status', PrinterSetting::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->firstOrFail();

        $template = $attributes['receipt_template_id'] === null
            ? null
            : $company->receiptTemplates()
                ->whereKey($attributes['receipt_template_id'])
                ->where('template_type', ReceiptTemplate::TYPE_ORDER_TICKET)
                ->firstOrFail();

        DB::transaction(function () use ($company, $printer, $template, $attributes): void {
            $printer->update([
                'name' => $attributes['printer_name'],
                'paper_width_mm' => $attributes['paper_width_mm'],
            ]);

            if ($template !== null) {
                $company->receiptTemplates()
                    ->where('template_type', ReceiptTemplate::TYPE_ORDER_TICKET)
                    ->update(['is_default' => false]);
                $template->update(['is_default' => true]);
            }
        });

        return response()->json(['data' => $this->data($company)]);
    }

    /** @return array<string, mixed> */
    private function data($company): array
    {
        $printer = $company->printerSettings()
            ->where('status', PrinterSetting::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
        $templates = $company->receiptTemplates()
            ->where('template_type', ReceiptTemplate::TYPE_ORDER_TICKET)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
        $latest = $company->printJobs()
            ->with('order:id,code')
            ->latest('requested_at')
            ->latest('id')
            ->first();

        return [
            'printer' => $printer === null ? null : [
                'id' => $printer->id,
                'name' => $printer->name,
                'paper_width_mm' => $printer->paper_width_mm,
                'print_mode' => $printer->print_mode,
                'status' => $printer->status,
            ],
            'templates' => $templates->map(fn (ReceiptTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'is_default' => $template->is_default,
                'width_chars' => $template->width_chars,
                'includes_financials' => $template->includes_financials,
            ])->values(),
            'latest_job' => $latest === null ? null : [
                'id' => $latest->id,
                'order_code' => $latest->order?->code,
                'status' => $latest->status,
                'requested_at' => $latest->requested_at?->toIso8601String(),
            ],
            'recent_failures_count' => $company->printJobs()
                ->whereIn('status', [PrintJob::STATUS_FAILED, PrintJob::STATUS_PRINTER_UNAVAILABLE])
                ->where('failed_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }
}
