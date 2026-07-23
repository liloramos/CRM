<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StructuredMenuDateRequest;
use App\Services\Menu\DailyStructuredMenuService;
use Illuminate\Http\JsonResponse;

class DailyStructuredMenuController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(
        StructuredMenuDateRequest $request,
        DailyStructuredMenuService $dailyMenu,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');
        $date = $request->operationalDate($company->setting?->timezone ?: config('app.timezone'));

        return response()->json([
            'data' => $dailyMenu->day($company, $date),
        ]);
    }
}
