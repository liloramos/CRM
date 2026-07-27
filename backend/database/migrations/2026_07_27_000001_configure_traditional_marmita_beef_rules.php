<?php

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->configureProduct('n8-tradicional', 2000);
        $this->configureProduct('n9-tradicional', 2200);
    }

    public function down(): void
    {
        $this->deleteAdditionalGroup('n8-tradicional');
        $this->deleteAdditionalGroup('n9-tradicional');

        $this->restoreVariation('n8-tradicional', null, 0, false, true);
        $this->restoreVariation('n9-tradicional', 2200, 400, true, false);
    }

    private function configureProduct(string $productSlug, int $beefOnlyFinalPriceCents): void
    {
        $company = DB::table('companies')->where('slug', 'restaurante-sol')->first();

        if ($company === null) {
            return;
        }

        $product = DB::table('products')
            ->where('company_id', $company->id)
            ->where('slug', $productSlug)
            ->first();

        $beef = DB::table('menu_components')
            ->where('company_id', $company->id)
            ->where('slug', 'bife')
            ->first();

        if ($product === null || $beef === null) {
            return;
        }

        $basePriceCents = (int) ($product->base_price_cents ?? 0);

        $variationGroupId = $this->upsertGroup(
            companyId: (int) $company->id,
            productId: (int) $product->id,
            code: 'variacao_bife',
            label: 'Somente bife',
            mode: ProductSelectionMode::Variation->value,
            minQuantity: null,
            maxQuantity: null,
            sameComponentOnly: false,
            displayOrder: 10,
        );

        $this->upsertComponentLink(
            groupId: $variationGroupId,
            componentId: (int) $beef->id,
            priceDeltaCents: max(0, $beefOnlyFinalPriceCents - $basePriceCents),
            finalPriceCents: $beefOnlyFinalPriceCents,
            isActive: true,
            requiresConfirmation: false,
            displayOrder: 10,
        );

        $additionalGroupId = $this->upsertGroup(
            companyId: (int) $company->id,
            productId: (int) $product->id,
            code: 'bife_adicional',
            label: 'Bife adicional',
            mode: ProductSelectionMode::Addon->value,
            minQuantity: 0,
            maxQuantity: 1,
            sameComponentOnly: true,
            displayOrder: 20,
        );

        $this->upsertComponentLink(
            groupId: $additionalGroupId,
            componentId: (int) $beef->id,
            priceDeltaCents: 700,
            finalPriceCents: null,
            isActive: true,
            requiresConfirmation: false,
            displayOrder: 10,
        );
    }

    private function restoreVariation(
        string $productSlug,
        ?int $finalPriceCents,
        int $priceDeltaCents,
        bool $isActive,
        bool $requiresConfirmation,
    ): void {
        $company = DB::table('companies')->where('slug', 'restaurante-sol')->first();

        if ($company === null) {
            return;
        }

        $product = DB::table('products')
            ->where('company_id', $company->id)
            ->where('slug', $productSlug)
            ->first();

        $beef = DB::table('menu_components')
            ->where('company_id', $company->id)
            ->where('slug', 'bife')
            ->first();

        if ($product === null || $beef === null) {
            return;
        }

        $variationGroupId = $this->upsertGroup(
            companyId: (int) $company->id,
            productId: (int) $product->id,
            code: 'variacao_bife',
            label: 'Variacao com bife',
            mode: ProductSelectionMode::Variation->value,
            minQuantity: null,
            maxQuantity: null,
            sameComponentOnly: false,
            displayOrder: 10,
        );

        $this->upsertComponentLink(
            groupId: $variationGroupId,
            componentId: (int) $beef->id,
            priceDeltaCents: $priceDeltaCents,
            finalPriceCents: $finalPriceCents,
            isActive: $isActive,
            requiresConfirmation: $requiresConfirmation,
            displayOrder: 10,
        );
    }

    private function deleteAdditionalGroup(string $productSlug): void
    {
        $company = DB::table('companies')->where('slug', 'restaurante-sol')->first();

        if ($company === null) {
            return;
        }

        $product = DB::table('products')
            ->where('company_id', $company->id)
            ->where('slug', $productSlug)
            ->first();

        if ($product === null) {
            return;
        }

        $group = DB::table('product_option_groups')
            ->where('product_id', $product->id)
            ->where('code', 'bife_adicional')
            ->first();

        if ($group === null) {
            return;
        }

        DB::table('product_group_components')
            ->where('product_option_group_id', $group->id)
            ->delete();

        DB::table('product_option_groups')
            ->where('id', $group->id)
            ->delete();
    }

    private function upsertGroup(
        int $companyId,
        int $productId,
        string $code,
        string $label,
        string $mode,
        ?int $minQuantity,
        ?int $maxQuantity,
        bool $sameComponentOnly,
        int $displayOrder,
    ): int {
        $now = now();
        $group = DB::table('product_option_groups')
            ->where('product_id', $productId)
            ->where('code', $code)
            ->first();

        $values = [
            'company_id' => $companyId,
            'product_id' => $productId,
            'code' => $code,
            'label' => $label,
            'selection_mode' => $mode,
            'selection_actor' => ProductSelectionActor::Customer->value,
            'is_required' => false,
            'min_choices' => 0,
            'max_choices' => 1,
            'min_quantity' => $minQuantity,
            'max_quantity' => $maxQuantity,
            'same_component_only' => $sameComponentOnly,
            'included_in_base_price' => false,
            'display_order' => $displayOrder,
            'updated_at' => $now,
        ];

        if ($group !== null) {
            DB::table('product_option_groups')->where('id', $group->id)->update($values);

            return (int) $group->id;
        }

        return (int) DB::table('product_option_groups')->insertGetId([
            ...$values,
            'created_at' => $now,
        ]);
    }

    private function upsertComponentLink(
        int $groupId,
        int $componentId,
        int $priceDeltaCents,
        ?int $finalPriceCents,
        bool $isActive,
        bool $requiresConfirmation,
        int $displayOrder,
    ): void {
        $now = now();
        $link = DB::table('product_group_components')
            ->where('product_option_group_id', $groupId)
            ->where('menu_component_id', $componentId)
            ->first();

        $values = [
            'product_option_group_id' => $groupId,
            'menu_component_id' => $componentId,
            'price_delta_cents' => $priceDeltaCents,
            'final_price_cents' => $finalPriceCents,
            'included_quantity' => null,
            'is_default' => false,
            'is_active' => $isActive,
            'requires_confirmation' => $requiresConfirmation,
            'display_order' => $displayOrder,
            'updated_at' => $now,
        ];

        if ($link !== null) {
            DB::table('product_group_components')->where('id', $link->id)->update($values);

            return;
        }

        DB::table('product_group_components')->insert([
            ...$values,
            'created_at' => $now,
        ]);
    }
};
