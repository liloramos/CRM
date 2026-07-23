<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StructuredMenuDateRequest;
use App\Models\Product;
use App\Services\Menu\StructuredProductConfigurationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductConfigurationController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(
        StructuredMenuDateRequest $request,
        Product $product,
        StructuredProductConfigurationService $configuration,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        $date = $request->operationalDate($company->setting?->timezone ?: config('app.timezone'));

        return response()->json([
            'data' => $configuration->configuration($product, $company, $date),
        ]);
    }
}
