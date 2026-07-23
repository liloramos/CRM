<?php

namespace Database\Seeders;

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductGroupProduct;
use App\Models\ProductOptionGroup;
use Illuminate\Database\Seeder;
use RuntimeException;

class SolRestaurantProductRuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SolRestaurantOfficialMenuSeeder::class);

        $company = $this->solRestaurant();

        $this->seedN5CasaRules($company);
        $this->seedN8CasaRules($company);
        $this->seedSucoRules($company);
        $this->seedComboLatinhaRules($company);
        $this->seedBifeVariationRules($company);
    }

    private function seedN5CasaRules(Company $company): void
    {
        $product = $this->product($company, 'n5-casa');

        $bases = $this->group($company, $product, [
            'code' => 'bases_fixas',
            'label' => 'Bases fixas',
            'selection_mode' => ProductSelectionMode::Fixed,
            'selection_actor' => ProductSelectionActor::System,
            'is_required' => true,
            'min_choices' => 4,
            'max_choices' => 4,
            'included_in_base_price' => true,
            'display_order' => 10,
        ]);

        $this->syncComponentLinks($bases, ['arroz', 'feijao', 'macarrao', 'mandioca']);

        $salad = $this->group($company, $product, [
            'code' => 'salada_casa',
            'label' => 'Salada escolhida pela casa',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::House,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'included_in_base_price' => true,
            'display_order' => 20,
        ]);

        $this->syncComponentLinks($salad, ['beterraba', 'cenoura']);

        $meat = $this->group($company, $product, [
            'code' => 'carne',
            'label' => 'Carne',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => 1,
            'max_quantity' => 1,
            'same_component_only' => true,
            'included_in_base_price' => true,
            'display_order' => 30,
        ]);

        $this->syncComponentLinks($meat, ['almondega', 'porco', 'frango-ao-molho']);
    }

    private function seedN8CasaRules(Company $company): void
    {
        $product = $this->product($company, 'n8-casa');

        $bases = $this->group($company, $product, [
            'code' => 'bases_fixas',
            'label' => 'Bases fixas',
            'selection_mode' => ProductSelectionMode::Fixed,
            'selection_actor' => ProductSelectionActor::System,
            'is_required' => true,
            'min_choices' => 4,
            'max_choices' => 4,
            'included_in_base_price' => true,
            'display_order' => 10,
        ]);

        $this->syncComponentLinks($bases, ['arroz', 'feijao', 'macarrao', 'mandioca']);

        $salad = $this->group($company, $product, [
            'code' => 'salada',
            'label' => 'Escolha uma salada',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'included_in_base_price' => true,
            'display_order' => 20,
        ]);

        $this->syncComponentLinks($salad, ['repolho-com-tomate', 'vinagrete', 'beterraba', 'cenoura']);

        $meat = $this->group($company, $product, [
            'code' => 'carne',
            'label' => 'Carne',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'min_quantity' => 2,
            'max_quantity' => 2,
            'same_component_only' => true,
            'included_in_base_price' => true,
            'display_order' => 30,
        ]);

        $this->syncComponentLinks($meat, ['almondega', 'porco', 'frango-ao-molho', 'bife-de-figado']);
    }

    private function seedSucoRules(Company $company): void
    {
        $product = $this->product($company, 'suco');

        $flavor = $this->group($company, $product, [
            'code' => 'sabor',
            'label' => 'Escolha o sabor',
            'selection_mode' => ProductSelectionMode::Single,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'included_in_base_price' => true,
            'display_order' => 10,
        ]);

        $this->syncComponentLinks($flavor, [
            'goiaba',
            'tamarindo',
            'acerola',
            'abacaxi',
            'abacaxi-com-hortela',
            'caju',
            'limao',
        ]);
    }

    private function seedComboLatinhaRules(Company $company): void
    {
        $product = $this->product($company, 'combo-n8-com-latinha');

        $beverage = $this->group($company, $product, [
            'code' => 'bebida_combo',
            'label' => 'Escolha a bebida',
            'selection_mode' => ProductSelectionMode::IncludedChoice,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => true,
            'min_choices' => 1,
            'max_choices' => 1,
            'included_in_base_price' => true,
            'display_order' => 10,
        ]);

        $this->syncProductLinks($beverage, [
            'guarana-lata',
            'mineiro-lata',
            'coca-cola-lata-normal',
            'coca-cola-zero-lata',
            'mineiro-lata-zero',
        ]);
    }

    private function seedBifeVariationRules(Company $company): void
    {
        $n9 = $this->group($company, $this->product($company, 'n9-tradicional'), [
            'code' => 'variacao_bife',
            'label' => 'Variação com bife',
            'selection_mode' => ProductSelectionMode::Variation,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => false,
            'min_choices' => 0,
            'max_choices' => 1,
            'included_in_base_price' => false,
            'display_order' => 10,
        ]);

        $this->syncComponentLinks($n9, [
            'bife' => [
                'price_delta_cents' => 400,
                'final_price_cents' => 2200,
                'requires_confirmation' => false,
                'is_active' => true,
            ],
        ]);

        $n8 = $this->group($company, $this->product($company, 'n8-tradicional'), [
            'code' => 'variacao_bife',
            'label' => 'Variação com bife',
            'selection_mode' => ProductSelectionMode::Variation,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => false,
            'min_choices' => 0,
            'max_choices' => 1,
            'included_in_base_price' => false,
            'display_order' => 10,
        ]);

        $this->syncComponentLinks($n8, [
            'bife' => [
                'price_delta_cents' => 0,
                'final_price_cents' => null,
                'requires_confirmation' => true,
                'is_active' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function group(Company $company, Product $product, array $row): ProductOptionGroup
    {
        return ProductOptionGroup::query()->updateOrCreate(
            ['product_id' => $product->id, 'code' => $row['code']],
            [
                'company_id' => $company->id,
                'label' => $row['label'],
                'selection_mode' => $row['selection_mode'],
                'selection_actor' => $row['selection_actor'],
                'is_required' => $row['is_required'],
                'min_choices' => $row['min_choices'] ?? null,
                'max_choices' => $row['max_choices'] ?? null,
                'min_quantity' => $row['min_quantity'] ?? null,
                'max_quantity' => $row['max_quantity'] ?? null,
                'same_component_only' => $row['same_component_only'] ?? false,
                'included_in_base_price' => $row['included_in_base_price'],
                'display_order' => $row['display_order'],
            ],
        );
    }

    /**
     * @param  array<int|string, string|array<string, mixed>>  $links
     */
    private function syncComponentLinks(ProductOptionGroup $group, array $links): void
    {
        foreach ($links as $key => $value) {
            $slug = is_string($key) ? $key : $value;
            $overrides = is_array($value) ? $value : [];

            ProductGroupComponent::query()->updateOrCreate(
                [
                    'product_option_group_id' => $group->id,
                    'menu_component_id' => $this->component($group->company, $slug)->id,
                ],
                [
                    'price_delta_cents' => $overrides['price_delta_cents'] ?? 0,
                    'final_price_cents' => $overrides['final_price_cents'] ?? null,
                    'included_quantity' => $overrides['included_quantity'] ?? null,
                    'is_default' => $overrides['is_default'] ?? false,
                    'is_active' => $overrides['is_active'] ?? true,
                    'requires_confirmation' => $overrides['requires_confirmation'] ?? false,
                    'display_order' => (($this->linkPosition($links, $key) + 1) * 10),
                ],
            );
        }
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function syncProductLinks(ProductOptionGroup $group, array $slugs): void
    {
        foreach ($slugs as $index => $slug) {
            ProductGroupProduct::query()->updateOrCreate(
                [
                    'product_option_group_id' => $group->id,
                    'selectable_product_id' => $this->product($group->company, $slug)->id,
                ],
                [
                    'price_delta_cents' => 0,
                    'final_price_cents' => null,
                    'included_quantity' => null,
                    'is_default' => false,
                    'is_active' => true,
                    'requires_confirmation' => false,
                    'display_order' => ($index + 1) * 10,
                ],
            );
        }
    }

    private function solRestaurant(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function product(Company $company, string $slug): Product
    {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOr(fn () => throw new RuntimeException("Produto oficial ausente: {$slug}"));
    }

    private function component(Company $company, string $slug): MenuComponent
    {
        return MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOr(fn () => throw new RuntimeException("Componente oficial ausente: {$slug}"));
    }

    /**
     * @param  array<int|string, mixed>  $links
     */
    private function linkPosition(array $links, int|string $key): int
    {
        return array_search($key, array_keys($links), true) ?: 0;
    }
}
