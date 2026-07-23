<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class SolRestaurantProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SolRestaurantMenuCategorySeeder::class);

        $company = $this->solRestaurant();
        $categories = ProductCategory::query()
            ->where('company_id', $company->id)
            ->whereIn('slug', collect($this->products())->pluck('category')->unique()->all())
            ->get()
            ->keyBy('slug');

        foreach ($this->products() as $row) {
            $category = $categories->get($row['category']);

            Product::query()->updateOrCreate(
                ['company_id' => $company->id, 'slug' => $row['slug']],
                [
                    'category_id' => $category->id,
                    'name' => $row['name'],
                    'product_type' => $row['product_type'],
                    'menu_rule_code' => $row['menu_rule_code'],
                    'description' => $row['description'] ?? null,
                    'base_price_cents' => $row['base_price_cents'],
                    'currency' => 'BRL',
                    'is_active' => true,
                    'is_available_by_default' => true,
                    'allows_item_notes' => true,
                    'notes_hint' => $row['notes_hint'] ?? 'Aceita observações por item no pedido.',
                    'composition_rules' => $row['composition_rules'] ?? null,
                    'metadata' => $row['metadata'] ?? null,
                    'display_order' => $row['display_order'],
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
    private function products(): array
    {
        return [
            [
                'slug' => 'n5-casa',
                'category' => 'marmitas',
                'name' => 'N5 Casa',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n5_casa',
                'base_price_cents' => 800,
                'display_order' => 10,
            ],
            [
                'slug' => 'n8-casa',
                'category' => 'marmitas',
                'name' => 'N8 Casa',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n8_casa',
                'base_price_cents' => 1300,
                'display_order' => 20,
            ],
            [
                'slug' => 'n8-tradicional',
                'category' => 'marmitas',
                'name' => 'N8 Tradicional',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n8_tradicional',
                'base_price_cents' => 1600,
                'display_order' => 30,
            ],
            [
                'slug' => 'n9-tradicional',
                'category' => 'marmitas',
                'name' => 'N9 Tradicional',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n9_tradicional',
                'base_price_cents' => 1800,
                'display_order' => 40,
            ],
            [
                'slug' => 'combo-n8-casa-baby',
                'category' => 'combos',
                'name' => 'Combo N8 Casa Baby',
                'product_type' => Product::TYPE_COMBO,
                'menu_rule_code' => 'combo_n8_casa_baby',
                'base_price_cents' => 1500,
                'metadata' => [
                    'official_combo' => true,
                ],
                'display_order' => 50,
            ],
            [
                'slug' => 'combo-n8-com-latinha',
                'category' => 'combos',
                'name' => 'Combo N8 com Latinha',
                'product_type' => Product::TYPE_COMBO,
                'menu_rule_code' => 'combo_n8_com_latinha',
                'base_price_cents' => 2000,
                'metadata' => [
                    'official_combo' => true,
                ],
                'display_order' => 60,
            ],
            [
                'slug' => 'feijoada-250ml',
                'category' => 'feijoadas',
                'name' => 'Feijoada 250 ml',
                'product_type' => Product::TYPE_FEIJOADA,
                'menu_rule_code' => 'feijoada_250ml',
                'base_price_cents' => 800,
                'display_order' => 70,
            ],
            [
                'slug' => 'feijoada-n5-500ml',
                'category' => 'feijoadas',
                'name' => 'Feijoada N5 / 500 ml',
                'product_type' => Product::TYPE_FEIJOADA,
                'menu_rule_code' => 'feijoada_n5_500ml',
                'base_price_cents' => 1500,
                'display_order' => 80,
            ],
            [
                'slug' => 'feijoada-750ml',
                'category' => 'feijoadas',
                'name' => 'Feijoada 750 ml',
                'product_type' => Product::TYPE_FEIJOADA,
                'menu_rule_code' => 'feijoada_750ml',
                'base_price_cents' => 1800,
                'display_order' => 90,
            ],
            [
                'slug' => 'feijoada-grande-1100ml',
                'category' => 'feijoadas',
                'name' => 'Feijoada grande / 1100 ml',
                'product_type' => Product::TYPE_FEIJOADA,
                'menu_rule_code' => 'feijoada_grande_1100ml',
                'base_price_cents' => 2200,
                'display_order' => 100,
            ],
            [
                'slug' => 'acai-500ml',
                'category' => 'acai',
                'name' => 'Açaí 500 ml',
                'product_type' => Product::TYPE_ACAI,
                'menu_rule_code' => 'acai_500ml',
                'base_price_cents' => 1500,
                'display_order' => 110,
            ],
            [
                'slug' => 'suco',
                'category' => 'sucos',
                'name' => 'Suco',
                'product_type' => Product::TYPE_JUICE,
                'menu_rule_code' => 'suco',
                'base_price_cents' => 700,
                'display_order' => 120,
            ],
            [
                'slug' => 'coca-cola-2l',
                'category' => 'bebidas',
                'name' => 'Coca-Cola 2L',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'coca_cola_2l',
                'base_price_cents' => 1300,
                'display_order' => 130,
            ],
            [
                'slug' => 'sprite-zero',
                'category' => 'bebidas',
                'name' => 'Sprite Zero',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'sprite_zero',
                'base_price_cents' => 1100,
                'display_order' => 140,
            ],
            [
                'slug' => 'coca-cola-1l',
                'category' => 'bebidas',
                'name' => 'Coca-Cola 1L',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'coca_cola_1l',
                'base_price_cents' => 1000,
                'display_order' => 150,
            ],
            [
                'slug' => 'coca-cola-1l-zero',
                'category' => 'bebidas',
                'name' => 'Coca-Cola 1L Zero',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'coca_cola_1l_zero',
                'base_price_cents' => 1000,
                'display_order' => 160,
            ],
            [
                'slug' => 'guarana-1l',
                'category' => 'bebidas',
                'name' => 'Guaraná 1L',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'guarana_1l',
                'base_price_cents' => 800,
                'display_order' => 170,
            ],
            [
                'slug' => 'mineiro-2l',
                'category' => 'bebidas',
                'name' => 'Mineiro 2L',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'mineiro_2l',
                'base_price_cents' => 1000,
                'display_order' => 180,
            ],
            [
                'slug' => 'h2o-limonetto',
                'category' => 'bebidas',
                'name' => 'H2O Limonetto',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'h2o_limonetto',
                'base_price_cents' => 700,
                'display_order' => 190,
            ],
            [
                'slug' => 'guarana-lata',
                'category' => 'bebidas',
                'name' => 'Guaraná lata',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'guarana_lata',
                'base_price_cents' => 500,
                'display_order' => 200,
            ],
            [
                'slug' => 'guarana-mineiro-baby',
                'category' => 'bebidas',
                'name' => 'Guaraná Mineiro Baby',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'guarana_mineiro_baby',
                'base_price_cents' => 300,
                'display_order' => 210,
            ],
            [
                'slug' => 'mineiro-lata',
                'category' => 'bebidas',
                'name' => 'Mineiro lata',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'mineiro_lata',
                'base_price_cents' => 500,
                'display_order' => 220,
            ],
            [
                'slug' => 'coca-cola-lata-normal',
                'category' => 'bebidas',
                'name' => 'Coca-Cola lata normal',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'coca_cola_lata_normal',
                'base_price_cents' => 500,
                'display_order' => 230,
            ],
            [
                'slug' => 'coca-cola-zero-lata',
                'category' => 'bebidas',
                'name' => 'Coca-Cola Zero lata',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'coca_cola_zero_lata',
                'base_price_cents' => 500,
                'display_order' => 240,
            ],
            [
                'slug' => 'mineiro-600ml',
                'category' => 'bebidas',
                'name' => 'Mineiro 600 ml',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'mineiro_600ml',
                'base_price_cents' => 600,
                'display_order' => 250,
            ],
            [
                'slug' => 'agua-com-gas',
                'category' => 'bebidas',
                'name' => 'Água com gás',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'agua_com_gas',
                'base_price_cents' => 400,
                'display_order' => 260,
            ],
            [
                'slug' => 'agua-mineral',
                'category' => 'bebidas',
                'name' => 'Água mineral',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'agua_mineral',
                'base_price_cents' => 300,
                'display_order' => 270,
            ],
            [
                'slug' => 'coca-cola-600ml',
                'category' => 'bebidas',
                'name' => 'Coca-Cola 600 ml',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'coca_cola_600ml',
                'base_price_cents' => 800,
                'display_order' => 280,
            ],
            [
                'slug' => 'mineiro-lata-zero',
                'category' => 'bebidas',
                'name' => 'Mineiro lata Zero',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'mineiro_lata_zero',
                'base_price_cents' => 500,
                'display_order' => 290,
            ],
        ];
    }
}
