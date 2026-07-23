<?php

namespace Database\Seeders;

use App\Enums\MenuComponentType;
use App\Models\Company;
use App\Models\MenuComponent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SolRestaurantMenuComponentSeeder extends Seeder
{
    public function run(): void
    {
        $company = $this->solRestaurant();

        foreach ($this->components() as $type => $names) {
            foreach (array_values($names) as $index => $name) {
                MenuComponent::query()->updateOrCreate(
                    ['company_id' => $company->id, 'slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'component_type' => $type,
                        'description' => $this->descriptionFor($name),
                        'default_price_delta_cents' => 0,
                        'is_active' => true,
                        'display_order' => ($index + 1) * 10,
                    ],
                );
            }
        }
    }

    private function solRestaurant(): Company
    {
        return Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function components(): array
    {
        return [
            MenuComponentType::Base->value => [
                'Arroz',
                'Feijão',
                'Macarrão',
                'Mandioca',
            ],
            MenuComponentType::Hot->value => [
                'Arroz branco',
                'Arroz amarelo',
                'Feijão tradicional',
                'Tutu de feijão',
                'Macarrão vermelho',
                'Macarrão alho e óleo',
                'Batata ao molho',
                'Chuchu com cenoura',
                'Repolho alho e óleo',
                'Repolho com maionese',
                'Farofa de cenoura',
                'Jiló',
                'Feijão preto',
                'Purê de batata',
                'Banana frita',
                'Feijão tropeiro',
                'Mix de legumes',
                'Batata frita',
                'Abóbora cabotiá',
            ],
            MenuComponentType::Salad->value => [
                'Beterraba',
                'Cenoura',
                'Repolho com tomate',
                'Vinagrete',
                'Alface',
                'Tomate',
                'Tabule',
                'Batata doce',
                'Maionese',
                'Salada de berinjela',
                'Couve',
                'Pepino',
                'Salada de macarrão',
                'Salada de cebola',
                'Farofa',
                'Abobrinha',
            ],
            MenuComponentType::Meat->value => [
                'Almôndega',
                'Porco',
                'Frango ao molho',
                'Bife de fígado',
                'Frango frito',
                'Ovo frito',
                'Disquinho',
                'Filé de frango empanado',
                'Strogonoff de frango',
                'Filé de peixe',
                'Filé de frango',
                'Strogonoff',
                'Linguiça',
                'Feijoada',
                'Bife',
            ],
            MenuComponentType::Extra->value => [
                'Chuchu refogado',
                'Cenoura refogada',
                'Quiabo',
            ],
            MenuComponentType::JuiceFlavor->value => [
                'Goiaba',
                'Tamarindo',
                'Acerola',
                'Abacaxi',
                'Abacaxi com hortelã',
                'Caju',
                'Limão',
            ],
        ];
    }

    private function descriptionFor(string $name): ?string
    {
        return match ($name) {
            'Bife' => 'A DOCX cita bife como variação/adicional; preço operacional será modelado em etapa posterior.',
            'Ovo frito' => 'Cadastrado como carne do cardápio semanal; o papel de adicional pago permanece pendente.',
            default => null,
        };
    }
}
