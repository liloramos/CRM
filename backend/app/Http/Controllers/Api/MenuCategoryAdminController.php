<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreMenuCategoryRequest;
use App\Http\Requests\Menu\UpdateMenuCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\Menu\MenuCategoryManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuCategoryAdminController extends Controller
{
    use ResolvesOperationalCompany;

    public function store(StoreMenuCategoryRequest $request, MenuCategoryManagementService $categories): JsonResponse
    {
        $category = $categories->create($this->resolveCompany($request), $request->validated());

        return response()->json(['data' => new ProductCategoryResource($category)], 201);
    }

    public function update(
        UpdateMenuCategoryRequest $request,
        ProductCategory $category,
        MenuCategoryManagementService $categories,
    ): JsonResponse {
        return response()->json([
            'data' => new ProductCategoryResource(
                $categories->update($this->resolveCompany($request), $category, $request->validated()),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        ProductCategory $category,
        MenuCategoryManagementService $categories,
    ): JsonResponse {
        $categories->delete($this->resolveCompany($request), $category);

        return response()->json([
            'data' => [
                'outcome' => 'deleted',
                'message' => 'Categoria excluida.',
            ],
        ]);
    }
}
