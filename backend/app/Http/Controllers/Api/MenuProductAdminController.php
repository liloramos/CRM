<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\UpdateMenuProductRequest;
use App\Http\Requests\Menu\UpdateProductGroupComponentRequest;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Services\Menu\MenuProductManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class MenuProductAdminController extends Controller
{
    use ResolvesOperationalCompany;

    public function update(
        UpdateMenuProductRequest $request,
        Product $product,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');
        $timezone = $company->setting?->timezone ?: config('app.timezone');
        $requestedDate = $request->validated('date', null);
        $date = is_string($requestedDate)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate, $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();

        return response()->json([
            'data' => $products->updateProduct(
                company: $company,
                product: $product,
                attributes: $request->validated(),
                serviceDays: $request->serviceDays(),
                date: $date,
            ),
        ]);
    }

    public function updateComponentOption(
        UpdateProductGroupComponentRequest $request,
        ProductGroupComponent $option,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');
        $timezone = $company->setting?->timezone ?: config('app.timezone');
        $requestedDate = $request->validated('date', null);
        $date = is_string($requestedDate)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate, $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();

        return response()->json([
            'data' => $products->updateComponentOption(
                company: $company,
                option: $option,
                attributes: $request->validated(),
                date: $date,
            ),
        ]);
    }
}
