<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Company;
use App\Services\Menu\MenuAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AvailableMenuController extends Controller
{
    public function __invoke(
        Request $request,
        Company $company,
        MenuAvailabilityService $availabilityService,
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])
            : null;

        return ProductResource::collection(
            $availabilityService->availableProducts($company, $date)->get(),
        );
    }
}
