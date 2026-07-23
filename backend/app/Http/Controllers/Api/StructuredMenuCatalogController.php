<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StructuredMenuDateRequest;
use App\Services\Menu\StructuredMenuCatalogService;
use Illuminate\Http\JsonResponse;

class StructuredMenuCatalogController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(
        StructuredMenuDateRequest $request,
        StructuredMenuCatalogService $catalog,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');
        $date = $request->operationalDate($company->setting?->timezone ?: config('app.timezone'));

        return response()->json([
            'data' => $catalog->catalog($company, $date),
        ]);
    }
}
