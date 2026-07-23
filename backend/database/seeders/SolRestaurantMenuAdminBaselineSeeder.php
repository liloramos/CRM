<?php

namespace Database\Seeders;

use App\Enums\MenuComponentType;
use App\Enums\ProductServiceDay as ProductServiceDayEnum;
use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductServiceDay;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuComponentItem;
use Illuminate\Database\Seeder;

class SolRestaurantMenuAdminBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $weeklyMenu = WeeklyMenu::query()
            ->where('company_id', $company->id)
            ->where('slug', 'cardapio-semanal-oficial')
            ->firstOrFail();

        $filePeixe = $this->component($company, 'file-de-peixe');
        $filePeixe->update(['name' => 'Filé de peixe empanado']);

        $churrasco = $this->ensureComponent($company, 'Churrasco', 'churrasco', MenuComponentType::Meat);
        $bisteca = $this->ensureComponent($company, 'Bisteca de porco na chapa', 'bisteca-de-porco-na-chapa', MenuComponentType::Meat);

        foreach (WeeklyMenuServiceDay::cases() as $day) {
            $this->ensureWeeklyMeat($company, $weeklyMenu, $day, $this->component($company, 'porco'));
        }

        $this->ensureWeeklyMeat($company, $weeklyMenu, WeeklyMenuServiceDay::Monday, $churrasco);
        $this->ensureWeeklyMeat($company, $weeklyMenu, WeeklyMenuServiceDay::Tuesday, $churrasco);

        WeeklyMenuComponentItem::query()
            ->where('company_id', $company->id)
            ->where('menu_component_id', $filePeixe->id)
            ->where(function ($query): void {
                $query->where('service_day', '!=', WeeklyMenuServiceDay::Wednesday->value)
                    ->orWhere('section', '!=', WeeklyMenuSection::Meat->value);
            })
            ->update(['is_active' => false]);

        WeeklyMenuComponentItem::query()
            ->where('company_id', $company->id)
            ->where('menu_component_id', $bisteca->id)
            ->update(['is_active' => false]);

        foreach ($this->productSchedules() as $slug => $days) {
            $product = Product::query()
                ->where('company_id', $company->id)
                ->where('slug', $slug)
                ->first();

            if (! $product instanceof Product) {
                continue;
            }

            if (in_array($slug, ['latinha', 'latinha-zero', 'ovo-frito-adicional'], true)) {
                $product->update([
                    'is_active' => true,
                    'is_available_by_default' => true,
                ]);
            }

            $this->syncProductDays($product, $days);
        }
    }

    private function component(Company $company, string $slug): MenuComponent
    {
        return MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function ensureComponent(Company $company, string $name, string $slug, MenuComponentType $type): MenuComponent
    {
        $component = MenuComponent::query()->firstOrNew(['company_id' => $company->id, 'slug' => $slug]);

        $component->fill([
            'name' => $name,
            'component_type' => $type,
            'is_active' => true,
            'default_price_delta_cents' => 0,
            'display_order' => $component->exists ? $component->display_order : $this->nextComponentOrder($company, $type),
        ]);
        $component->save();

        return $component;
    }

    private function ensureWeeklyMeat(
        Company $company,
        WeeklyMenu $weeklyMenu,
        WeeklyMenuServiceDay $day,
        MenuComponent $component,
    ): void {
        $item = WeeklyMenuComponentItem::query()
            ->where('weekly_menu_id', $weeklyMenu->id)
            ->where('service_day', $day->value)
            ->where('section', WeeklyMenuSection::Meat->value)
            ->where('menu_component_id', $component->id)
            ->first();

        if (! $item instanceof WeeklyMenuComponentItem) {
            $item = new WeeklyMenuComponentItem([
                'weekly_menu_id' => $weeklyMenu->id,
                'service_day' => $day->value,
                'section' => WeeklyMenuSection::Meat->value,
                'menu_component_id' => $component->id,
            ]);
        }

        $item->fill([
            'company_id' => $company->id,
            'is_active' => true,
            'display_order' => $item->display_order ?: $this->nextWeeklyOrder($weeklyMenu, $day, WeeklyMenuSection::Meat),
            'notes' => $item->notes,
        ]);
        $item->save();
    }

    /**
     * @param  array<int, string>  $days
     */
    private function syncProductDays(Product $product, array $days): void
    {
        foreach (ProductServiceDayEnum::cases() as $day) {
            ProductServiceDay::query()->updateOrCreate(
                [
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'service_day' => $day->value,
                ],
                [
                    'is_active' => in_array($day->value, $days, true),
                ],
            );
        }
    }

    private function nextComponentOrder(Company $company, MenuComponentType $type): int
    {
        $maxOrder = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('component_type', $type->value)
            ->max('display_order');

        return ((int) $maxOrder) + 10;
    }

    private function nextWeeklyOrder(WeeklyMenu $weeklyMenu, WeeklyMenuServiceDay $day, WeeklyMenuSection $section): int
    {
        $maxOrder = WeeklyMenuComponentItem::query()
            ->where('weekly_menu_id', $weeklyMenu->id)
            ->where('service_day', $day->value)
            ->where('section', $section->value)
            ->max('display_order');

        return ((int) $maxOrder) + 10;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function productSchedules(): array
    {
        $mondayToSaturday = [
            ProductServiceDayEnum::Monday->value,
            ProductServiceDayEnum::Tuesday->value,
            ProductServiceDayEnum::Wednesday->value,
            ProductServiceDayEnum::Thursday->value,
            ProductServiceDayEnum::Friday->value,
            ProductServiceDayEnum::Saturday->value,
        ];

        $saturdayOnly = [ProductServiceDayEnum::Saturday->value];

        return [
            'n5-casa' => $mondayToSaturday,
            'n8-casa' => $mondayToSaturday,
            'n8-tradicional' => $mondayToSaturday,
            'n9-tradicional' => $mondayToSaturday,
            'combo-n8-casa-baby' => $mondayToSaturday,
            'combo-n8-com-latinha' => $mondayToSaturday,
            'suco' => $mondayToSaturday,
            'acai-500ml' => $mondayToSaturday,
            'latinha' => $mondayToSaturday,
            'latinha-zero' => $mondayToSaturday,
            'ovo-frito-adicional' => $mondayToSaturday,
            'coca-cola-2l' => $mondayToSaturday,
            'sprite-zero' => $mondayToSaturday,
            'coca-cola-1l' => $mondayToSaturday,
            'coca-cola-1l-zero' => $mondayToSaturday,
            'guarana-1l' => $mondayToSaturday,
            'mineiro-2l' => $mondayToSaturday,
            'h2o-limonetto' => $mondayToSaturday,
            'guarana-lata' => $mondayToSaturday,
            'guarana-mineiro-baby' => $mondayToSaturday,
            'mineiro-lata' => $mondayToSaturday,
            'coca-cola-lata-normal' => $mondayToSaturday,
            'coca-cola-zero-lata' => $mondayToSaturday,
            'mineiro-600ml' => $mondayToSaturday,
            'agua-com-gas' => $mondayToSaturday,
            'agua-mineral' => $mondayToSaturday,
            'coca-cola-600ml' => $mondayToSaturday,
            'mineiro-lata-zero' => $mondayToSaturday,
            'feijoada-250ml' => $saturdayOnly,
            'feijoada-n5-500ml' => $saturdayOnly,
            'feijoada-750ml' => $saturdayOnly,
            'feijoada-grande-1100ml' => $saturdayOnly,
        ];
    }
}
