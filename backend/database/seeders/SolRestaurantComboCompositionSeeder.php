<?php

namespace Database\Seeders;

use App\Enums\ComboItemPriceBehavior;
use App\Enums\ComboItemPrintMode;
use App\Models\ComboItem;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

class SolRestaurantComboCompositionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SolRestaurantOfficialMenuSeeder::class);

        $company = $this->solRestaurant();

        $this->syncComboItem($company, 'combo-n8-casa-baby', 'n8-casa', 10);
        $this->syncComboItem($company, 'combo-n8-casa-baby', 'guarana-mineiro-baby', 20);
        $this->syncComboItem($company, 'combo-n8-com-latinha', 'n8-tradicional', 10);
    }

    private function syncComboItem(Company $company, string $comboSlug, string $includedSlug, int $displayOrder): void
    {
        ComboItem::query()->updateOrCreate(
            [
                'combo_product_id' => $this->product($company, $comboSlug)->id,
                'included_product_id' => $this->product($company, $includedSlug)->id,
            ],
            [
                'company_id' => $company->id,
                'quantity' => 1,
                'price_behavior' => ComboItemPriceBehavior::Included,
                'price_delta_cents' => 0,
                'print_mode' => ComboItemPrintMode::ChildLine,
                'display_order' => $displayOrder,
            ],
        );
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
            ->firstOr(fn () => throw new RuntimeException("Produto oficial ausente para composição de combo: {$slug}"));
    }
}
