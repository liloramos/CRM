<?php

namespace App\Services\Menu;

use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuComponentItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WeeklyMenuManagementService
{
    /**
     * @return array<string, mixed>
     */
    public function upsertComponent(
        Company $company,
        MenuComponent $component,
        WeeklyMenuServiceDay $serviceDay,
        WeeklyMenuSection $section,
        ?int $displayOrder,
        bool $isActive,
        ?string $notes,
    ): array {
        $this->assertComponentBelongsToCompany($component, $company);
        $weeklyMenu = $this->officialWeeklyMenu($company);

        $item = WeeklyMenuComponentItem::query()->updateOrCreate(
            [
                'weekly_menu_id' => $weeklyMenu->id,
                'service_day' => $serviceDay->value,
                'section' => $section->value,
                'menu_component_id' => $component->id,
            ],
            [
                'company_id' => $company->id,
                'is_active' => $isActive,
                'display_order' => $displayOrder ?? $this->nextDisplayOrder($weeklyMenu, $serviceDay, $section),
                'notes' => $notes,
            ],
        );

        return $this->itemPayload($item->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function updateItem(
        Company $company,
        WeeklyMenuComponentItem $item,
        WeeklyMenuServiceDay $serviceDay,
        WeeklyMenuSection $section,
        int $displayOrder,
        bool $isActive,
        ?string $notes,
    ): array {
        abort_unless((int) $item->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        $duplicate = WeeklyMenuComponentItem::query()
            ->where('weekly_menu_id', $item->weekly_menu_id)
            ->where('service_day', $serviceDay->value)
            ->where('section', $section->value)
            ->where('menu_component_id', $item->menu_component_id)
            ->whereKeyNot($item->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'component' => ['Este componente ja esta vinculado a este dia e secao.'],
            ]);
        }

        $item->fill([
            'service_day' => $serviceDay,
            'section' => $section,
            'display_order' => $displayOrder,
            'is_active' => $isActive,
            'notes' => $notes,
        ]);
        $item->save();

        return $this->itemPayload($item->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteItem(Company $company, WeeklyMenuComponentItem $item): array
    {
        abort_unless((int) $item->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        DB::transaction(fn () => $item->delete());

        return [
            'cleared' => true,
            'id' => $item->id,
        ];
    }

    private function assertComponentBelongsToCompany(MenuComponent $component, Company $company): void
    {
        abort_unless((int) $component->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
    }

    private function officialWeeklyMenu(Company $company): WeeklyMenu
    {
        return WeeklyMenu::query()
            ->where('company_id', $company->id)
            ->where('slug', 'cardapio-semanal-oficial')
            ->firstOrFail();
    }

    private function nextDisplayOrder(WeeklyMenu $weeklyMenu, WeeklyMenuServiceDay $serviceDay, WeeklyMenuSection $section): int
    {
        $maxOrder = WeeklyMenuComponentItem::query()
            ->where('weekly_menu_id', $weeklyMenu->id)
            ->where('service_day', $serviceDay->value)
            ->where('section', $section->value)
            ->max('display_order');

        return ((int) $maxOrder) + 10;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(WeeklyMenuComponentItem $item): array
    {
        $item->loadMissing('component');

        return [
            'id' => $item->id,
            'weekly_menu_id' => $item->weekly_menu_id,
            'service_day' => $item->service_day->value,
            'section' => $item->section->value,
            'display_order' => $item->display_order,
            'is_active' => (bool) $item->is_active,
            'notes' => $item->notes,
            'component' => [
                'id' => $item->component->id,
                'slug' => $item->component->slug,
                'name' => $item->component->name,
                'component_type' => $item->component->component_type->value,
            ],
        ];
    }
}
