<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Services\Reports\OperationalReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalReportController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(Request $request, OperationalReportService $reports): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $reports->report($this->resolveCompany($request), $validated['from'] ?? null, $validated['to'] ?? null),
        ]);
    }
}
