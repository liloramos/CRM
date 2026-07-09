<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Services\Operational\OperationalCrmPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalSnapshotController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(Request $request, OperationalCrmPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $presenter->snapshot($company),
            'meta' => [
                'source' => 'database',
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
