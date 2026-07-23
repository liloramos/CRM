<?php

namespace Database\Seeders;

use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuComponentItem;
use Illuminate\Database\Seeder;
use RuntimeException;

class SolRestaurantWeeklyMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SolRestaurantOfficialMenuSeeder::class);

        $company = $this->solRestaurant();
        $weeklyMenu = WeeklyMenu::query()->updateOrCreate(
            ['company_id' => $company->id, 'slug' => 'cardapio-semanal-oficial'],
            [
                'name' => 'Cardápio semanal oficial',
                'starts_on' => null,
                'ends_on' => null,
                'is_active' => true,
            ],
        );

        foreach ($this->weeklyMenu() as $day => $sections) {
            foreach ($sections as $section => $componentSlugs) {
                foreach ($componentSlugs as $index => $componentSlug) {
                    WeeklyMenuComponentItem::query()->updateOrCreate(
                        [
                            'weekly_menu_id' => $weeklyMenu->id,
                            'service_day' => $day,
                            'section' => $section,
                            'menu_component_id' => $this->component($company, $componentSlug)->id,
                        ],
                        [
                            'company_id' => $company->id,
                            'is_active' => true,
                            'display_order' => ($index + 1) * 10,
                            'notes' => $section === WeeklyMenuSection::Extra->value
                                ? 'Extra da semana; a disponibilidade diária futura poderá ocultar este item.'
                                : null,
                        ],
                    );
                }
            }
        }
    }

    private function solRestaurant(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function component(Company $company, string $slug): MenuComponent
    {
        return MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOr(fn () => throw new RuntimeException("Componente oficial ausente para cardápio semanal: {$slug}"));
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    private function weeklyMenu(): array
    {
        $extras = ['chuchu-refogado', 'cenoura-refogada', 'quiabo'];

        return [
            WeeklyMenuServiceDay::Monday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'tutu-de-feijao',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'batata-ao-molho',
                    'chuchu-com-cenoura',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'salada-de-cebola',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'frango-frito',
                    'bife-de-figado',
                    'ovo-frito',
                    'disquinho',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Tuesday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'tutu-de-feijao',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'farofa-de-cenoura',
                    'jilo',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-frango-empanado',
                    'strogonoff-de-frango',
                    'ovo-frito',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Wednesday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'feijao-preto',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'pure-de-batata',
                    'jilo',
                    'banana-frita',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'farofa',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-peixe',
                    'frango-frito',
                    'ovo-frito',
                    'bife-de-figado',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Thursday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tropeiro',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'mix-de-legumes',
                    'banana-frita',
                    'batata-frita',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'farofa',
                    'salada-de-macarrao',
                    'abobrinha',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-frango',
                    'strogonoff',
                    'ovo-frito',
                    'linguica',
                    'bife-de-figado',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Friday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'tutu-de-feijao',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'farofa-de-cenoura',
                    'abobora-cabotia',
                    'banana-frita',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'farofa',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'frango-ao-molho',
                    'file-de-frango-empanado',
                    'ovo-frito',
                    'linguica',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
            WeeklyMenuServiceDay::Saturday->value => [
                WeeklyMenuSection::Hot->value => [
                    'arroz-branco',
                    'arroz-amarelo',
                    'feijao-tradicional',
                    'pure-de-batata',
                    'macarrao-vermelho',
                    'macarrao-alho-e-oleo',
                    'mandioca',
                    'jilo',
                    'abobrinha',
                    'repolho-alho-e-oleo',
                    'repolho-com-tomate',
                    'repolho-com-maionese',
                ],
                WeeklyMenuSection::Salad->value => [
                    'alface',
                    'tomate',
                    'tabule',
                    'batata-doce',
                    'maionese',
                    'beterraba',
                    'cenoura',
                    'salada-de-berinjela',
                    'couve',
                    'vinagrete',
                    'pepino',
                    'salada-de-macarrao',
                    'farofa',
                ],
                WeeklyMenuSection::Meat->value => [
                    'almondega',
                    'porco',
                    'feijoada',
                    'file-de-frango',
                    'ovo-frito',
                    'linguica',
                    'bife-de-figado',
                    'bife',
                ],
                WeeklyMenuSection::Extra->value => $extras,
            ],
        ];
    }
}
