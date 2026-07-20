<?php

namespace App\Services\Menu;

use App\Models\Company;
use App\Models\DailyMenuOptionOverride;
use App\Models\DailyMenuOverride;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\WeeklyMenuItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
                'options' => fn (HasMany $query): HasMany => $this->availableOptionsQuery($query, $companyId, $availabilityDate),
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

    public function setOptionAvailability(
        Company $company,
        ProductOption $productOption,
        string $status,
        ?string $reason = null,
        ?int $markedByUserId = null,
        ?CarbonInterface $date = null,
    ): DailyMenuOptionOverride {
        $serviceDate = $date ?? now();

        return DailyMenuOptionOverride::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'product_option_id' => $productOption->id,
                'availability_date' => $serviceDate->toDateString(),
            ],
            [
                'status' => $status,
                'reason' => $reason,
                'marked_by_user_id' => $markedByUserId,
            ],
        );
    }

    private function availableOptionsQuery(HasMany $query, int $companyId, string $availabilityDate): HasMany
    {
        return $query
            ->active()
            ->with([
                'dailyMenuOptionOverrides' => fn (HasMany $query): HasMany => $query
                    ->where('company_id', $companyId)
                    ->whereDate('availability_date', $availabilityDate),
            ])
            ->orderBy('display_order')
            ->orderBy('name');
    }
}
