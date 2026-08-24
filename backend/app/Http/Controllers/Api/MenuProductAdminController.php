<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreMenuProductRequest;
use App\Http\Requests\Menu\UpdateMenuProductImageRequest;
use App\Http\Requests\Menu\UpdateMenuProductRequest;
use App\Http\Requests\Menu\UpdateProductGroupComponentRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Services\Menu\MenuProductManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuProductAdminController extends Controller
{
    use ResolvesOperationalCompany;

    public function store(
        StoreMenuProductRequest $request,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->createCounterProduct(
                company: $company,
                attributes: $request->validated(),
                serviceDays: $request->serviceDays(),
                date: $this->dateFor($request, $company),
            ),
        ], 201);
    }

    public function update(
        UpdateMenuProductRequest $request,
        Product $product,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->updateProduct(
                company: $company,
                product: $product,
                attributes: $request->validated(),
                serviceDays: $request->serviceDays(),
                date: $this->dateFor($request, $company),
            ),
        ]);
    }

    public function replaceImage(
        UpdateMenuProductImageRequest $request,
        Product $product,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->replaceProductImage($company, $product, $request->file('image'), $this->dateFor($request, $company)),
        ]);
    }

    public function removeImage(
        Request $request,
        Product $product,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->removeProductImage($company, $product, $this->dateFor($request, $company)),
        ]);
    }

    public function updateComponentOption(
        UpdateProductGroupComponentRequest $request,
        ProductGroupComponent $option,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->updateComponentOption(
                company: $company,
                option: $option,
                attributes: $request->validated(),
                date: $this->dateFor($request, $company),
            ),
        ]);
    }

    private function dateFor(Request $request, Company $company): CarbonImmutable
    {
        $timezone = $company->setting?->timezone ?: config('app.timezone');
        $requestedDate = $request->input('date');

        return is_string($requestedDate)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate, $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();
    }
}
