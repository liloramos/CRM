<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\ClearComponentAvailabilityRequest;
use App\Http\Requests\Menu\UpdateProductComponentAvailabilityRequest;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Services\Menu\MenuComponentAvailabilityService;
use Illuminate\Http\JsonResponse;

class ProductComponentAvailabilityController extends Controller
{
    use ResolvesOperationalCompany;

    public function update(
        UpdateProductComponentAvailabilityRequest $request,
        Product $product,
        MenuComponent $component,
        MenuComponentAvailabilityService $availability,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $availability->setProductOverride(
                company: $company,
                product: $product,
                component: $component,
                date: $request->availabilityDate(),
                status: $request->status(),
                reason: $request->validated('reason'),
                markedByUserId: $request->user()?->id,
            ),
        ]);
    }

    public function destroy(
        ClearComponentAvailabilityRequest $request,
        Product $product,
        MenuComponent $component,
        MenuComponentAvailabilityService $availability,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $availability->clearProductOverride(
                company: $company,
                product: $product,
                component: $component,
                date: $request->availabilityDate(),
            ),
        ]);
    }
}
