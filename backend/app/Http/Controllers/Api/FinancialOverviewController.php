<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Financial\FinancialOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialOverviewController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(Request $request, FinancialOverviewService $financial): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['nullable', Rule::in(Payment::METHODS)],
            'status' => ['nullable', Rule::in([...Payment::STATUSES, 'voided', 'concluded', 'pending_group', 'cancelled_group'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $financial->overview($this->resolveCompany($request), $validated)]);
    }
}
