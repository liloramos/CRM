<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuItem;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        $categories = $this->seedCategories($company);
        $products = $this->seedProducts($company, $categories);

        $this->seedOptions($company, $products);
        $this->seedWeeklyMenu($company, $products);
    }

    /**
     * @return array<string, ProductCategory>
     */
    private function seedCategories(Company $company): array
    {
        $categoryRows = [
            ['slug' => 'marmitas', 'name' => 'Marmitas', 'category_type' => ProductCategory::TYPE_MARMITAS, 'display_order' => 10],
            ['slug' => 'combos', 'name' => 'Combos', 'category_type' => ProductCategory::TYPE_COMBOS, 'display_order' => 20],
            ['slug' => 'bebidas', 'name' => 'Bebidas', 'category_type' => ProductCategory::TYPE_BEBIDAS, 'display_order' => 30],
            ['slug' => 'sucos', 'name' => 'Sucos', 'category_type' => ProductCategory::TYPE_SUCOS, 'display_order' => 40],
            ['slug' => 'feijoadas', 'name' => 'Feijoadas', 'category_type' => ProductCategory::TYPE_FEIJOADAS, 'display_order' => 50],
            ['slug' => 'adicionais', 'name' => 'Adicionais', 'category_type' => ProductCategory::TYPE_ADICIONAIS, 'display_order' => 60],
        ];

        $categories = [];

        foreach ($categoryRows as $row) {
            $categories[$row['slug']] = ProductCategory::query()->updateOrCreate(
                ['company_id' => $company->id, 'slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'category_type' => $row['category_type'],
                    'display_order' => $row['display_order'],
                    'is_active' => true,
                ],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, ProductCategory>  $categories
     * @return array<string, Product>
     */
    private function seedProducts(Company $company, array $categories): array
    {
        $products = [
            'n5-casa' => [
                'category' => 'marmitas',
                'name' => 'N5 Casa',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n5_casa',
                'base_price_cents' => 800,
                'composition_rules' => [
                    'base_items' => ['arroz', 'feijao', 'macarrao', 'mandioca'],
                    'salad_selection' => 'house',
                    'salad_options' => ['beterraba', 'cenoura'],
                    'meat_pieces' => 1,
                    'meat_mixing_allowed' => false,
                    'uses_weekly_menu' => false,
                ],
                'display_order' => 10,
            ],
            'n8-casa' => [
                'category' => 'marmitas',
                'name' => 'N8 Casa',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n8_casa',
                'base_price_cents' => 1300,
                'composition_rules' => [
                    'base_items' => ['arroz', 'feijao', 'macarrao', 'mandioca'],
                    'salad_selection' => 'customer',
                    'salad_options' => ['repolho_com_tomate', 'vinagrete', 'beterraba', 'cenoura'],
                    'meat_pieces' => 2,
                    'meat_mixing_allowed' => false,
                    'uses_weekly_menu' => false,
                ],
                'display_order' => 20,
            ],
            'n8-tradicional' => [
                'category' => 'marmitas',
                'name' => 'N8 Tradicional',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n8_tradicional',
                'base_price_cents' => 1600,
                'composition_rules' => [
                    'uses_weekly_menu' => true,
                    'accepts_bife_variation' => true,
                ],
                'display_order' => 30,
            ],
            'n9-tradicional' => [
                'category' => 'marmitas',
                'name' => 'N9 Tradicional',
                'product_type' => Product::TYPE_MARMITA,
                'menu_rule_code' => 'n9_tradicional',
                'base_price_cents' => 1800,
                'composition_rules' => [
                    'uses_weekly_menu' => true,
                ],
                'display_order' => 40,
            ],
            'combo-n8-casa-baby' => [
                'category' => 'combos',
                'name' => 'Combo N8 Casa Baby',
                'product_type' => Product::TYPE_COMBO,
                'menu_rule_code' => 'combo_n8_casa_baby',
                'base_price_cents' => 1500,
                'composition_rules' => [
                    'base_product' => 'n8_casa',
                    'official_combo' => true,
                ],
                'display_order' => 50,
            ],
            'combo-n8-com-latinha' => [
                'category' => 'combos',
                'name' => 'Combo N8 com latinha',
                'product_type' => Product::TYPE_COMBO,
                'menu_rule_code' => 'combo_n8_com_latinha',
                'base_price_cents' => 2000,
                'composition_rules' => [
                    'base_product' => 'n8_tradicional',
                    'includes' => ['latinha'],
                    'official_combo' => true,
                ],
                'display_order' => 60,
            ],
            'latinha' => [
                'category' => 'bebidas',
                'name' => 'Latinha',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'latinha',
                'base_price_cents' => 500,
                'composition_rules' => [
                    'zero_version_same_price' => true,
                ],
                'display_order' => 70,
            ],
            'latinha-zero' => [
                'category' => 'bebidas',
                'name' => 'Latinha Zero',
                'product_type' => Product::TYPE_BEVERAGE,
                'menu_rule_code' => 'latinha_zero',
                'base_price_cents' => 500,
                'composition_rules' => [
                    'zero_version_same_price' => true,
                ],
                'display_order' => 80,
            ],
            'suco' => [
                'category' => 'sucos',
                'name' => 'Suco',
                'product_type' => Product::TYPE_JUICE,
                'menu_rule_code' => 'suco',
                'base_price_cents' => 700,
                'display_order' => 90,
            ],
            'feijoada' => [
                'category' => 'feijoadas',
                'name' => 'Feijoada',
                'product_type' => Product::TYPE_FEIJOADA,
                'menu_rule_code' => 'feijoada',
                'base_price_cents' => null,
                'is_active' => false,
                'is_available_by_default' => false,
                'notes_hint' => 'Preco oficial pendente. Ativar somente apos cadastro operacional.',
                'metadata' => [
                    'official_price_pending' => true,
                ],
                'display_order' => 100,
            ],
            'ovo-frito-adicional' => [
                'category' => 'adicionais',
                'name' => 'Ovo frito adicional',
                'product_type' => Product::TYPE_ADDON,
                'menu_rule_code' => 'ovo_frito_adicional',
                'base_price_cents' => 200,
                'display_order' => 110,
            ],
        ];

        $createdProducts = [];

        foreach ($products as $slug => $row) {
            $category = $categories[$row['category']];

            $createdProducts[$slug] = Product::query()->updateOrCreate(
                ['company_id' => $company->id, 'slug' => $slug],
                [
                    'category_id' => $category->id,
                    'name' => $row['name'],
                    'product_type' => $row['product_type'],
                    'menu_rule_code' => $row['menu_rule_code'],
                    'description' => $row['description'] ?? null,
                    'base_price_cents' => $row['base_price_cents'],
                    'currency' => 'BRL',
                    'is_active' => $row['is_active'] ?? true,
                    'is_available_by_default' => $row['is_available_by_default'] ?? true,
                    'allows_item_notes' => true,
                    'notes_hint' => $row['notes_hint'] ?? 'Aceita observacoes por item no pedido.',
                    'composition_rules' => $row['composition_rules'] ?? null,
                    'metadata' => $row['metadata'] ?? null,
                    'display_order' => $row['display_order'],
                ],
            );
        }

        return $createdProducts;
    }

    /**
     * @param  array<string, Product>  $products
     */
    private function seedOptions(Company $company, array $products): void
    {
        ProductOption::query()->updateOrCreate(
            ['company_id' => $company->id, 'product_id' => null, 'slug' => 'ovo-frito'],
            [
                'name' => 'Ovo frito',
                'option_type' => ProductOption::TYPE_ADDON,
                'group_code' => 'adicionais',
                'price_delta_cents' => 200,
                'max_quantity' => null,
                'is_required' => false,
                'is_active' => true,
                'rules' => ['source' => 'official_menu'],
                'display_order' => 10,
            ],
        );

        foreach (['n5-casa', 'n8-casa'] as $productSlug) {
            foreach ([
                'arroz' => 'Arroz',
                'feijao' => 'Feijao',
                'macarrao' => 'Macarrao',
                'mandioca' => 'Mandioca',
            ] as $slug => $name) {
                $this->createProductOption($company, $products[$productSlug], [
                    'slug' => "guarnicao-{$slug}",
                    'name' => $name,
                    'option_type' => ProductOption::TYPE_CHOICE,
                    'group_code' => 'guarnicoes',
                    'price_delta_cents' => 0,
                    'rules' => ['component_kind' => 'base_or_side'],
                    'display_order' => 12,
                ]);
            }
        }

        foreach (['n5-casa', 'n8-casa', 'n8-tradicional', 'n9-tradicional'] as $productSlug) {
            $this->createProductOption($company, $products[$productSlug], [
                'slug' => 'ovo-frito',
                'name' => 'Ovo frito',
                'option_type' => ProductOption::TYPE_ADDON,
                'group_code' => 'adicionais',
                'price_delta_cents' => 200,
                'rules' => ['source' => 'official_menu'],
                'display_order' => 18,
            ]);
        }

        $this->createProductOption($company, $products['n8-tradicional'], [
            'slug' => 'bife-somente-bife',
            'name' => 'Bife somente bife',
            'option_type' => ProductOption::TYPE_VARIATION,
            'group_code' => 'bife',
            'price_delta_cents' => 400,
            'rules' => ['final_price_cents' => 2000],
            'display_order' => 20,
        ]);

        $this->createProductOption($company, $products['n8-tradicional'], [
            'slug' => 'bife-com-outras-carnes',
            'name' => 'Bife com outras carnes/adicional',
            'option_type' => ProductOption::TYPE_VARIATION,
            'group_code' => 'bife',
            'price_delta_cents' => 700,
            'rules' => ['final_price_cents' => 2300],
            'display_order' => 30,
        ]);

        foreach (['n5-casa', 'n8-casa'] as $productSlug) {
            foreach ([
                'repolho-com-tomate' => 'Repolho com tomate',
                'vinagrete' => 'Vinagrete',
                'beterraba' => 'Beterraba',
                'cenoura' => 'Cenoura',
            ] as $slug => $name) {
                $this->createProductOption($company, $products[$productSlug], [
                    'slug' => $slug,
                    'name' => $name,
                    'option_type' => ProductOption::TYPE_CHOICE,
                    'group_code' => 'salada',
                    'price_delta_cents' => 0,
                    'is_required' => true,
                    'rules' => ['min_choices' => 1, 'max_choices' => 1],
                    'display_order' => 40,
                ]);
            }
        }

        foreach ([
            'bebida-latinha' => 'Latinha',
            'bebida-latinha-zero' => 'Latinha Zero',
        ] as $slug => $name) {
            $this->createProductOption($company, $products['combo-n8-com-latinha'], [
                'slug' => $slug,
                'name' => $name,
                'option_type' => ProductOption::TYPE_CHOICE,
                'group_code' => 'bebidas',
                'price_delta_cents' => 0,
                'is_required' => true,
                'rules' => ['min_choices' => 1, 'max_choices' => 1],
                'display_order' => 50,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createProductOption(Company $company, Product $product, array $row): void
    {
        ProductOption::query()->updateOrCreate(
            ['company_id' => $company->id, 'product_id' => $product->id, 'slug' => $row['slug']],
            [
                'name' => $row['name'],
                'option_type' => $row['option_type'],
                'group_code' => $row['group_code'] ?? null,
                'price_delta_cents' => $row['price_delta_cents'] ?? 0,
                'max_quantity' => $row['max_quantity'] ?? null,
                'is_required' => $row['is_required'] ?? false,
                'is_active' => $row['is_active'] ?? true,
                'rules' => $row['rules'] ?? null,
                'display_order' => $row['display_order'] ?? 0,
            ],
        );
    }

    /**
     * @param  array<string, Product>  $products
     */
    private function seedWeeklyMenu(Company $company, array $products): void
    {
        $weeklyMenu = WeeklyMenu::query()->updateOrCreate(
            ['company_id' => $company->id, 'slug' => 'cardapio-base'],
            [
                'name' => 'Cardapio base',
                'starts_on' => null,
                'ends_on' => null,
                'is_active' => true,
            ],
        );

        foreach ($products as $product) {
            if (! $product->is_active || ! $product->is_available_by_default) {
                continue;
            }

            WeeklyMenuItem::query()->updateOrCreate(
                [
                    'weekly_menu_id' => $weeklyMenu->id,
                    'product_id' => $product->id,
                    'service_day' => WeeklyMenuItem::DAY_EVERYDAY,
                ],
                [
                    'is_available_by_default' => true,
                    'notes' => null,
                    'display_order' => $product->display_order,
                ],
            );
        }
    }
}
