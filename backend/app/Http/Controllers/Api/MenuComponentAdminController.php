<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreMenuComponentRequest;
use App\Http\Requests\Menu\UpdateMenuComponentRequest;
use App\Models\MenuComponent;
use App\Services\Menu\MenuComponentManagementService;
use Illuminate\Http\JsonResponse;

class MenuComponentAdminController extends Controller
{
    use ResolvesOperationalCompany;

    public function store(
        StoreMenuComponentRequest $request,
        MenuComponentManagementService $components,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $components->createComponent($company, $request->validated()),
        ], 201);
    }

    public function update(
        UpdateMenuComponentRequest $request,
        MenuComponent $component,
        MenuComponentManagementService $components,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => $components->updateComponent($company, $component, $request->validated()),
        ]);
    }
}
