<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductOptionResource;
use App\Models\DailyMenuOptionOverride;
use App\Models\ProductOption;
use App\Services\Menu\MenuAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class MenuOptionAvailabilityController extends Controller
{
    use ResolvesOperationalCompany;

    public function update(
        Request $request,
        ProductOption $productOption,
        MenuAvailabilityService $availabilityService,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        abort_unless((int) $productOption->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                DailyMenuOptionOverride::STATUS_AVAILABLE,
                DailyMenuOptionOverride::STATUS_UNAVAILABLE,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])
            : null;

        $availabilityService->setOptionAvailability(
            company: $company,
            productOption: $productOption,
            status: $validated['status'],
            reason: $validated['reason'] ?? null,
            markedByUserId: $request->user()?->id,
            date: $date,
        );

        return response()->json([
            'data' => new ProductOptionResource($productOption->refresh()->load([
                'dailyMenuOptionOverrides' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereDate('availability_date', ($date ?? now())->toDateString()),
            ])),
        ]);
    }
}
