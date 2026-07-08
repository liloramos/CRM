<?php

namespace App\Services\Menu;

use App\Models\Company;
use App\Models\DailyMenuOverride;
use App\Models\Product;
use App\Models\WeeklyMenuItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class MenuAvailabilityService
{
    public function availableProducts(Company|int $company, ?CarbonInterface $date = null): Builder
    {
        $companyId = $company instanceof Company ? $company->id : $company;
        $serviceDate = $date ?? now();
        $availabilityDate = $serviceDate->toDateString();
        $dayKey = WeeklyMenuItem::DAY_KEYS[$serviceDate->dayOfWeek] ?? WeeklyMenuItem::DAY_EVERYDAY;

        $unavailableProductIds = DailyMenuOverride::query()
            ->where('company_id', $companyId)
            ->whereDate('availability_date', $availabilityDate)
            ->where('status', DailyMenuOverride::STATUS_UNAVAILABLE)
            ->pluck('product_id');

        $availableOverrideProductIds = DailyMenuOverride::query()
            ->where('company_id', $companyId)
            ->whereDate('availability_date', $availabilityDate)
            ->where('status', DailyMenuOverride::STATUS_AVAILABLE)
            ->pluck('product_id');

        return Product::query()
            ->with([
                'category',
                'options' => fn (Builder $query): Builder => $query
                    ->active()
                    ->orderBy('display_order')
                    ->orderBy('name'),
            ])
            ->where('company_id', $companyId)
            ->active()
            ->whereHas('category', fn (Builder $query): Builder => $query->active())
            ->whereNotIn('id', $unavailableProductIds)
            ->where(function (Builder $query) use ($availableOverrideProductIds, $dayKey): void {
                $query->whereIn('id', $availableOverrideProductIds)
                    ->orWhere(function (Builder $query) use ($dayKey): void {
                        $query->availableByDefault()
                            ->whereHas('weeklyMenuItems', function (Builder $query) use ($dayKey): void {
                                $query->where('is_available_by_default', true)
                                    ->whereIn('service_day', [WeeklyMenuItem::DAY_EVERYDAY, $dayKey])
                                    ->whereHas('weeklyMenu', fn (Builder $query): Builder => $query->active());
                            });
                    });
            })
            ->orderBy('display_order')
            ->orderBy('name');
    }
}
