<?php

namespace App\Services\Menu;

use App\Enums\DailyMenuAdjustmentAction;
use App\Enums\WeeklyMenuSection;
use App\Models\Company;
use App\Models\DailyMenuComponentAdjustment;
use App\Models\MenuComponent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DailyMenuComponentAdjustmentService
{
    /**
     * @return array<string, mixed>
     */
    public function setAdjustment(
        Company $company,
        MenuComponent $component,
        CarbonInterface $date,
        WeeklyMenuSection $section,
        DailyMenuAdjustmentAction $action,
        ?int $displayOrder,
        ?string $notes,
        ?int $markedByUserId,
    ): array {
        $this->assertComponentBelongsToCompany($component, $company);

        $adjustment = DB::transaction(function () use (
            $company,
            $component,
            $date,
            $section,
            $action,
            $displayOrder,
            $notes,
            $markedByUserId,
        ): DailyMenuComponentAdjustment {
            $adjustment = DailyMenuComponentAdjustment::query()
                ->where('company_id', $company->id)
                ->whereDate('availability_date', $date->toDateString())
                ->where('menu_component_id', $component->id)
                ->where('section', $section->value)
                ->first();

            $adjustment ??= new DailyMenuComponentAdjustment([
                'company_id' => $company->id,
                'availability_date' => $date->toDateString(),
                'menu_component_id' => $component->id,
                'section' => $section->value,
            ]);

            $adjustment->fill([
                'action' => $action,
                'display_order' => $displayOrder,
                'notes' => $notes,
                'marked_by_user_id' => $markedByUserId,
            ]);
            $adjustment->save();

            return $adjustment;
        });

        return $this->payload($adjustment->refresh(), cleared: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearAdjustment(
        Company $company,
        MenuComponent $component,
        CarbonInterface $date,
        WeeklyMenuSection $section,
    ): array {
        $this->assertComponentBelongsToCompany($component, $company);

        DB::transaction(function () use ($company, $component, $date, $section): void {
            DailyMenuComponentAdjustment::query()
                ->where('company_id', $company->id)
                ->where('menu_component_id', $component->id)
                ->whereDate('availability_date', $date->toDateString())
                ->where('section', $section->value)
                ->delete();
        });

        return [
            'cleared' => true,
            'date' => $date->toDateString(),
            'section' => $section->value,
            'component' => [
                'id' => $component->id,
                'slug' => $component->slug,
                'name' => $component->name,
                'component_type' => $component->component_type->value,
            ],
        ];
    }

    private function assertComponentBelongsToCompany(MenuComponent $component, Company $company): void
    {
        abort_unless((int) $component->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DailyMenuComponentAdjustment $adjustment, bool $cleared): array
    {
        $adjustment->loadMissing('component');

        return [
            'cleared' => $cleared,
            'id' => $adjustment->id,
            'date' => $adjustment->availability_date->toDateString(),
            'section' => $adjustment->section->value,
            'action' => $adjustment->action->value,
            'display_order' => $adjustment->display_order,
            'notes' => $adjustment->notes,
            'marked_by_user_id' => $adjustment->marked_by_user_id,
            'component' => [
                'id' => $adjustment->component->id,
                'slug' => $adjustment->component->slug,
                'name' => $adjustment->component->name,
                'component_type' => $adjustment->component->component_type->value,
            ],
        ];
    }
}
