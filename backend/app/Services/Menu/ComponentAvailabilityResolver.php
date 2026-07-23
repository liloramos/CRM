<?php

namespace App\Services\Menu;

use App\Data\Menu\ComponentAvailabilityResult;
use App\Enums\MenuAvailabilityStatus;
use App\Models\Company;
use App\Models\DailyComponentAvailability;
use App\Models\DailyProductComponentOverride;
use App\Models\MenuComponent;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ComponentAvailabilityResolver
{
    /**
     * @var array<int, array<string, Collection<int, DailyComponentAvailability>>>
     */
    private array $globalAvailabilityCache = [];

    /**
     * @var array<int, array<int, array<string, Collection<int, DailyProductComponentOverride>>>>
     */
    private array $productOverrideCache = [];

    public function resolve(
        Company $company,
        MenuComponent $component,
        CarbonInterface|string $date,
        ?Product $product = null,
    ): ComponentAvailabilityResult {
        $availabilityDate = $this->dateString($date);

        if ($product !== null) {
            $productOverride = $this->productOverrides($company, $product, $availabilityDate)
                ->get((int) $component->id);

            if ($productOverride instanceof DailyProductComponentOverride) {
                return $this->result(
                    status: $productOverride->status,
                    source: ComponentAvailabilityResult::SOURCE_PRODUCT_OVERRIDE,
                    reason: $productOverride->reason,
                    replacementComponent: null,
                    availabilityDate: $availabilityDate,
                );
            }
        }

        $globalAvailability = $this->globalAvailabilities($company, $availabilityDate)
            ->get((int) $component->id);

        if ($globalAvailability instanceof DailyComponentAvailability) {
            return $this->result(
                status: $globalAvailability->status,
                source: ComponentAvailabilityResult::SOURCE_GLOBAL_AVAILABILITY,
                reason: $globalAvailability->reason,
                replacementComponent: $globalAvailability->replacementComponent,
                availabilityDate: $availabilityDate,
            );
        }

        return $this->result(
            status: $component->is_active ? MenuAvailabilityStatus::Available : MenuAvailabilityStatus::Unavailable,
            source: ComponentAvailabilityResult::SOURCE_COMPONENT_DEFAULT,
            reason: null,
            replacementComponent: null,
            availabilityDate: $availabilityDate,
        );
    }

    private function result(
        MenuAvailabilityStatus $status,
        string $source,
        ?string $reason,
        ?MenuComponent $replacementComponent,
        string $availabilityDate,
    ): ComponentAvailabilityResult {
        return new ComponentAvailabilityResult(
            status: $status,
            available: $status === MenuAvailabilityStatus::Available,
            source: $source,
            reason: $reason,
            replacementComponent: $replacementComponent,
            availabilityDate: $availabilityDate,
        );
    }

    private function dateString(CarbonInterface|string $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date)->toDateString();
    }

    /**
     * @return Collection<int, DailyComponentAvailability>
     */
    private function globalAvailabilities(Company $company, string $availabilityDate): Collection
    {
        $companyId = (int) $company->id;

        if (! isset($this->globalAvailabilityCache[$companyId][$availabilityDate])) {
            $this->globalAvailabilityCache[$companyId][$availabilityDate] = DailyComponentAvailability::query()
                ->with('replacementComponent')
                ->where('company_id', $companyId)
                ->whereDate('availability_date', $availabilityDate)
                ->get()
                ->keyBy('menu_component_id');
        }

        return $this->globalAvailabilityCache[$companyId][$availabilityDate];
    }

    /**
     * @return Collection<int, DailyProductComponentOverride>
     */
    private function productOverrides(Company $company, Product $product, string $availabilityDate): Collection
    {
        $companyId = (int) $company->id;
        $productId = (int) $product->id;

        if (! isset($this->productOverrideCache[$companyId][$productId][$availabilityDate])) {
            $this->productOverrideCache[$companyId][$productId][$availabilityDate] = DailyProductComponentOverride::query()
                ->where('company_id', $companyId)
                ->where('product_id', $productId)
                ->whereDate('availability_date', $availabilityDate)
                ->get()
                ->keyBy('menu_component_id');
        }

        return $this->productOverrideCache[$companyId][$productId][$availabilityDate];
    }
}
