<?php

namespace App\Services\Menu;

use App\Enums\MenuAvailabilityStatus;
use App\Models\Company;
use App\Models\DailyComponentAvailability;
use App\Models\DailyProductComponentOverride;
use App\Models\MenuComponent;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MenuComponentAvailabilityService
{
    public function __construct(private readonly ComponentAvailabilityResolver $resolver) {}

    /**
     * @return array<string, mixed>
     */
    public function setGlobalAvailability(
        Company $company,
        MenuComponent $component,
        CarbonInterface $date,
        MenuAvailabilityStatus $status,
        ?string $reason,
        ?int $replacementComponentId,
        ?int $markedByUserId,
    ): array {
        $this->assertComponentBelongsToCompany($component, $company);
        $replacement = $this->replacementComponent($company, $component, $replacementComponentId);

        $availability = DB::transaction(function () use (
            $company,
            $component,
            $date,
            $status,
            $reason,
            $replacement,
            $markedByUserId,
        ): DailyComponentAvailability {
            $availability = DailyComponentAvailability::query()
                ->where('company_id', $company->id)
                ->where('menu_component_id', $component->id)
                ->whereDate('availability_date', $date->toDateString())
                ->first();

            if (! $availability instanceof DailyComponentAvailability) {
                $availability = new DailyComponentAvailability([
                    'company_id' => $company->id,
                    'menu_component_id' => $component->id,
                    'availability_date' => $date->toDateString(),
                ]);
            }

            $availability->fill([
                'status' => $status,
                'reason' => $reason,
                'replacement_component_id' => $replacement?->id,
                'marked_by_user_id' => $markedByUserId,
            ]);
            $availability->save();

            return $availability;
        });

        $this->resolver->forget($company, $date);

        return $this->globalPayload($company, $component, $date, $availability, cleared: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearGlobalAvailability(Company $company, MenuComponent $component, CarbonInterface $date): array
    {
        $this->assertComponentBelongsToCompany($component, $company);

        DB::transaction(function () use ($company, $component, $date): void {
            DailyComponentAvailability::query()
                ->where('company_id', $company->id)
                ->where('menu_component_id', $component->id)
                ->whereDate('availability_date', $date->toDateString())
                ->delete();
        });

        $this->resolver->forget($company, $date);

        return $this->globalPayload($company, $component, $date, availability: null, cleared: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function setProductOverride(
        Company $company,
        Product $product,
        MenuComponent $component,
        CarbonInterface $date,
        MenuAvailabilityStatus $status,
        ?string $reason,
        ?int $markedByUserId,
    ): array {
        $this->assertProductBelongsToCompany($product, $company);
        $this->assertComponentBelongsToCompany($component, $company);
        $this->assertComponentMayBeOverriddenForProduct($product, $component);

        $override = DB::transaction(function () use (
            $company,
            $product,
            $component,
            $date,
            $status,
            $reason,
            $markedByUserId,
        ): DailyProductComponentOverride {
            $override = DailyProductComponentOverride::query()
                ->where('company_id', $company->id)
                ->where('product_id', $product->id)
                ->where('menu_component_id', $component->id)
                ->whereDate('availability_date', $date->toDateString())
                ->first();

            if (! $override instanceof DailyProductComponentOverride) {
                $override = new DailyProductComponentOverride([
                    'company_id' => $company->id,
                    'product_id' => $product->id,
                    'menu_component_id' => $component->id,
                    'availability_date' => $date->toDateString(),
                ]);
            }

            $override->fill([
                'status' => $status,
                'reason' => $reason,
                'marked_by_user_id' => $markedByUserId,
            ]);
            $override->save();

            return $override;
        });

        $this->resolver->forget($company, $date, $product);

        return $this->productPayload($company, $product, $component, $date, $override, cleared: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearProductOverride(
        Company $company,
        Product $product,
        MenuComponent $component,
        CarbonInterface $date,
    ): array {
        $this->assertProductBelongsToCompany($product, $company);
        $this->assertComponentBelongsToCompany($component, $company);
        $this->assertComponentMayBeOverriddenForProduct($product, $component);

        DB::transaction(function () use ($company, $product, $component, $date): void {
            DailyProductComponentOverride::query()
                ->where('company_id', $company->id)
                ->where('product_id', $product->id)
                ->where('menu_component_id', $component->id)
                ->whereDate('availability_date', $date->toDateString())
                ->delete();
        });

        $this->resolver->forget($company, $date, $product);

        return $this->productPayload($company, $product, $component, $date, override: null, cleared: true);
    }

    private function assertComponentBelongsToCompany(MenuComponent $component, Company $company): void
    {
        abort_unless((int) $component->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
    }

    private function assertProductBelongsToCompany(Product $product, Company $company): void
    {
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
    }

    private function assertComponentMayBeOverriddenForProduct(Product $product, MenuComponent $component): void
    {
        $linkedByGroup = $product->optionGroups()
            ->whereHas('componentOptions', function ($query) use ($component): void {
                $query->where('menu_component_id', $component->id)
                    ->where('is_active', true);
            })
            ->exists();

        if ($linkedByGroup) {
            return;
        }

        if ($this->usesWeeklyMenu($product) && $this->componentExistsInWeeklyMenu($product, $component)) {
            return;
        }

        throw ValidationException::withMessages([
            'component' => ['O componente não possui relação operacional com este produto.'],
        ]);
    }

    private function usesWeeklyMenu(Product $product): bool
    {
        if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return true;
        }

        return (bool) data_get($product->composition_rules, 'uses_weekly_menu', false);
    }

    private function componentExistsInWeeklyMenu(Product $product, MenuComponent $component): bool
    {
        return $component->weeklyMenuItems()
            ->where('company_id', $product->company_id)
            ->where('is_active', true)
            ->whereHas('weeklyMenu', fn ($query) => $query->where('company_id', $product->company_id)->active())
            ->exists();
    }

    private function replacementComponent(
        Company $company,
        MenuComponent $component,
        ?int $replacementComponentId,
    ): ?MenuComponent {
        if ($replacementComponentId === null) {
            return null;
        }

        if ((int) $replacementComponentId === (int) $component->id) {
            throw ValidationException::withMessages([
                'replacement_component_id' => ['O substituto não pode ser o próprio componente.'],
            ]);
        }

        $replacement = MenuComponent::query()
            ->where('company_id', $company->id)
            ->whereKey($replacementComponentId)
            ->first();

        if (! $replacement instanceof MenuComponent) {
            throw ValidationException::withMessages([
                'replacement_component_id' => ['O componente substituto informado não existe para esta empresa.'],
            ]);
        }

        if (! $replacement->is_active) {
            throw ValidationException::withMessages([
                'replacement_component_id' => ['O componente substituto precisa estar ativo.'],
            ]);
        }

        return $replacement;
    }

    /**
     * @return array<string, mixed>
     */
    private function globalPayload(
        Company $company,
        MenuComponent $component,
        CarbonInterface $date,
        ?DailyComponentAvailability $availability,
        bool $cleared,
    ): array {
        $availability?->loadMissing('replacementComponent');

        return [
            'scope' => 'global',
            'component' => $this->componentPayload($component),
            'date' => $date->toDateString(),
            'configured_status' => $availability?->status?->value,
            'reason' => $availability?->reason,
            'replacement' => $availability?->replacementComponent
                ? $this->componentPayload($availability->replacementComponent)
                : null,
            'effective_availability' => $this->resolver->resolve($company, $component, $date)->toArray(),
            'updated_at' => $availability?->updated_at?->toIso8601String(),
            'cleared' => $cleared,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(
        Company $company,
        Product $product,
        MenuComponent $component,
        CarbonInterface $date,
        ?DailyProductComponentOverride $override,
        bool $cleared,
    ): array {
        return [
            'scope' => 'product_override',
            'product' => $this->productPayloadSummary($product),
            'component' => $this->componentPayload($component),
            'date' => $date->toDateString(),
            'configured_status' => $override?->status?->value,
            'reason' => $override?->reason,
            'replacement' => null,
            'effective_availability' => $this->resolver->resolve($company, $component, $date, $product)->toArray(),
            'updated_at' => $override?->updated_at?->toIso8601String(),
            'cleared' => $cleared,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(MenuComponent $component): array
    {
        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'component_type' => $component->component_type->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayloadSummary(Product $product): array
    {
        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'product_type' => $product->product_type,
        ];
    }
}
