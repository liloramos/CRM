<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class SolRestaurantMenuCategorySeeder extends Seeder
{
    public function run(): void
    {
        $company = $this->solRestaurant();

        foreach ($this->categories() as $row) {
            ProductCategory::query()->updateOrCreate(
                ['company_id' => $company->id, 'slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'category_type' => $row['category_type'],
                    'description' => $row['description'] ?? null,
                    'display_order' => $row['display_order'],
                    'is_active' => true,
                ],
            );
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
     * @return array<int, array<string, mixed>>
     */
    private function categories(): array
    {
        return [
            ['slug' => 'marmitas', 'name' => 'Marmitas', 'category_type' => ProductCategory::TYPE_MARMITAS, 'display_order' => 10],
            ['slug' => 'combos', 'name' => 'Combos', 'category_type' => ProductCategory::TYPE_COMBOS, 'display_order' => 20],
            ['slug' => 'bebidas', 'name' => 'Bebidas', 'category_type' => ProductCategory::TYPE_BEBIDAS, 'display_order' => 30],
            ['slug' => 'sucos', 'name' => 'Sucos', 'category_type' => ProductCategory::TYPE_SUCOS, 'display_order' => 40],
            ['slug' => 'acai', 'name' => 'Açaí', 'category_type' => ProductCategory::TYPE_ACAI, 'display_order' => 50],
            ['slug' => 'feijoadas', 'name' => 'Feijoadas', 'category_type' => ProductCategory::TYPE_FEIJOADAS, 'display_order' => 60],
            ['slug' => 'adicionais', 'name' => 'Adicionais', 'category_type' => ProductCategory::TYPE_ADICIONAIS, 'display_order' => 70],
        ];
    }
}
