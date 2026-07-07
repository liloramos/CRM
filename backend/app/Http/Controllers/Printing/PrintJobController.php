<?php

namespace App\Http\Controllers\Printing;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PrintJobResource;
use App\Models\Order;
use App\Models\PrintJob;
use App\Services\Printing\PrintWorkflowService;
use Illuminate\Http\Request;

class PrintJobController extends Controller
{
    public function store(Request $request, Order $order, PrintWorkflowService $printing): PrintJobResource
    {
        $attributes = $request->validate([
            'target_audience' => ['nullable', 'string', 'max:60'],
            'receipt_template_id' => ['nullable', 'integer'],
            'printer_setting_id' => ['nullable', 'integer'],
        ]);

        $job = $printing->generateTicket($order, $request->user(), $attributes);

        return new PrintJobResource($job->load(['receiptTemplate', 'printerSetting', 'events']));
    }

    public function markPrinting(Request $request, PrintJob $printJob, PrintWorkflowService $printing): PrintJobResource
    {
        $job = $printing->markPrinting($printJob, $request->user());

        return new PrintJobResource($job->load(['receiptTemplate', 'printerSetting', 'events']));
    }

    public function markPrinted(Request $request, PrintJob $printJob, PrintWorkflowService $printing): PrintJobResource
    {
        $job = $printing->markPrinted($printJob, $request->user());

        return new PrintJobResource($job->load(['receiptTemplate', 'printerSetting', 'events']));
    }

    public function markFailed(Request $request, PrintJob $printJob, PrintWorkflowService $printing): PrintJobResource
    {
        $attributes = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
            'printer_unavailable' => ['nullable', 'boolean'],
        ]);

        $job = ($attributes['printer_unavailable'] ?? false)
            ? $printing->markPrinterUnavailable($printJob, $request->user(), $attributes['message'] ?? null)
            : $printing->failPrint($printJob, $request->user(), $attributes['message'] ?? null);

        return new PrintJobResource($job->load(['receiptTemplate', 'printerSetting', 'events']));
    }

    public function reprint(Request $request, PrintJob $printJob, PrintWorkflowService $printing): PrintJobResource
    {
        $attributes = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $job = $printing->requestReprint($printJob, $request->user(), $attributes['reason'] ?? null);

        return new PrintJobResource($job->load(['receiptTemplate', 'printerSetting', 'events']));
    }

    public function manualConfirmation(Request $request, Order $order, PrintWorkflowService $printing): OrderResource
    {
        $attributes = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $printing->markManualPrinted($order, $request->user(), $attributes['reason'] ?? null);

        return new OrderResource($order->load(['latestPrintJob', 'printJobEvents']));
    }

    public function waive(Request $request, Order $order, PrintWorkflowService $printing): OrderResource
    {
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $order = $printing->waivePrinting($order, $request->user(), $attributes['reason']);

        return new OrderResource($order->load(['latestPrintJob', 'printJobEvents']));
    }
}
