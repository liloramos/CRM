<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\UpdateWeeklyMenuComponentItemRequest;
use App\Http\Requests\Menu\UpsertWeeklyMenuComponentRequest;
use App\Models\MenuComponent;
use App\Models\WeeklyMenuComponentItem;
use App\Services\Menu\WeeklyMenuManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyMenuComponentAdminController extends Controller
{
    use ResolvesOperationalCompany;

    public function store(
        UpsertWeeklyMenuComponentRequest $request,
        MenuComponent $component,
        WeeklyMenuManagementService $weeklyMenu,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $weeklyMenu->upsertComponent(
                company: $company,
                component: $component,
                serviceDay: $request->serviceDay(),
                section: $request->section(),
                displayOrder: $request->validated('display_order'),
                isActive: (bool) $request->validated('is_active', true),
                notes: $request->validated('notes'),
            ),
        ]);
    }

    public function update(
        UpdateWeeklyMenuComponentItemRequest $request,
        WeeklyMenuComponentItem $item,
        WeeklyMenuManagementService $weeklyMenu,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $weeklyMenu->updateItem(
                company: $company,
                item: $item,
                serviceDay: $request->serviceDay(),
                section: $request->section(),
                displayOrder: (int) $request->validated('display_order'),
                isActive: (bool) $request->validated('is_active'),
                notes: $request->validated('notes'),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        WeeklyMenuComponentItem $item,
        WeeklyMenuManagementService $weeklyMenu,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $weeklyMenu->deleteItem($company, $item),
        ]);
    }
}
