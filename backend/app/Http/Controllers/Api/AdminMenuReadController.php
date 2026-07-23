<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StructuredMenuDateRequest;
use App\Services\Menu\AdminMenuReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMenuReadController extends Controller
{
    use ResolvesOperationalCompany;

    public function products(StructuredMenuDateRequest $request, AdminMenuReadService $menu): JsonResponse
    {
        $company = $this->resolveCompany($request)->loadMissing('setting');
        $timezone = $company->setting?->timezone ?: config('app.timezone');

        return response()->json([
            'data' => $menu->products($company, $request->operationalDate($timezone)),
        ]);
    }

    public function components(Request $request, AdminMenuReadService $menu): JsonResponse
    {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $menu->components($company),
        ]);
    }

    public function weekly(Request $request, AdminMenuReadService $menu): JsonResponse
    {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $menu->weekly($company),
        ]);
    }

    public function dayAdjustments(StructuredMenuDateRequest $request, AdminMenuReadService $menu): JsonResponse
    {
        $company = $this->resolveCompany($request)->loadMissing('setting');
        $timezone = $company->setting?->timezone ?: config('app.timezone');

        return response()->json([
            'data' => $menu->dayAdjustments($company, $request->operationalDate($timezone)),
        ]);
    }
}
