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
use Illuminate\Support\Facades\Storage;

class MenuProductAdminController extends Controller
{
    use ResolvesOperationalCompany;

    public function store(
        StoreMenuProductRequest $request,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->createProduct(
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

    public function destroy(
        Request $request,
        Product $product,
        MenuProductManagementService $products,
    ): JsonResponse {
        $company = $this->resolveCompany($request)->loadMissing('setting');

        return response()->json([
            'data' => $products->deleteProduct(
                company: $company,
                product: $product,
                date: $this->dateFor($request, $company),
                actorUserId: $request->user()?->id,
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

    public function image(Request $request, Product $product): mixed
    {
        $company = $this->resolveCompany($request);
        abort_unless((int) $product->company_id === (int) $company->id, 404);

        $path = data_get($product->metadata, 'catalog_image_path');
        abort_unless(
            is_string($path) && str_starts_with($path, "menu-products/{$company->id}/{$product->id}/"),
            404,
        );

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
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
