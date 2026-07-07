<?php

namespace App\Http\Controllers\Printing;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PrintJob;
use App\Services\Printing\PrintWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderTicketPreviewController extends Controller
{
    public function __invoke(Request $request, Order $order, PrintWorkflowService $printing): Response
    {
        $jobId = $request->query('print_job_id');

        $job = $jobId
            ? PrintJob::query()->where('order_id', $order->id)->findOrFail($jobId)
            : $printing->generateTicket($order, $request->user(), [
                'target_audience' => $request->query('target_audience', 'kitchen'),
            ]);

        return response($job->html_content, Response::HTTP_OK)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
