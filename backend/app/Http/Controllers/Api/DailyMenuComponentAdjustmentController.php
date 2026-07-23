<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\ClearDailyMenuComponentAdjustmentRequest;
use App\Http\Requests\Menu\UpsertDailyMenuComponentAdjustmentRequest;
use App\Models\MenuComponent;
use App\Services\Menu\DailyMenuComponentAdjustmentService;
use Illuminate\Http\JsonResponse;

class DailyMenuComponentAdjustmentController extends Controller
{
    use ResolvesOperationalCompany;

    public function update(
        UpsertDailyMenuComponentAdjustmentRequest $request,
        MenuComponent $component,
        DailyMenuComponentAdjustmentService $adjustments,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $adjustments->setAdjustment(
                company: $company,
                component: $component,
                date: $request->availabilityDate(),
                section: $request->section(),
                action: $request->action(),
                displayOrder: $request->validated('display_order'),
                notes: $request->validated('notes'),
                markedByUserId: $request->user()?->id,
            ),
        ]);
    }

    public function destroy(
        ClearDailyMenuComponentAdjustmentRequest $request,
        MenuComponent $component,
        DailyMenuComponentAdjustmentService $adjustments,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $adjustments->clearAdjustment(
                company: $company,
                component: $component,
                date: $request->availabilityDate(),
                section: $request->section(),
            ),
        ]);
    }
}
