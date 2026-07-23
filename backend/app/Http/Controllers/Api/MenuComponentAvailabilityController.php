<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\ClearComponentAvailabilityRequest;
use App\Http\Requests\Menu\UpdateComponentAvailabilityRequest;
use App\Models\MenuComponent;
use App\Services\Menu\MenuComponentAvailabilityService;
use Illuminate\Http\JsonResponse;

class MenuComponentAvailabilityController extends Controller
{
    use ResolvesOperationalCompany;

    public function update(
        UpdateComponentAvailabilityRequest $request,
        MenuComponent $component,
        MenuComponentAvailabilityService $availability,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $availability->setGlobalAvailability(
                company: $company,
                component: $component,
                date: $request->availabilityDate(),
                status: $request->status(),
                reason: $request->validated('reason'),
                replacementComponentId: $request->validated('replacement_component_id'),
                markedByUserId: $request->user()?->id,
            ),
        ]);
    }

    public function destroy(
        ClearComponentAvailabilityRequest $request,
        MenuComponent $component,
        MenuComponentAvailabilityService $availability,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $availability->clearGlobalAvailability(
                company: $company,
                component: $component,
                date: $request->availabilityDate(),
            ),
        ]);
    }
}
