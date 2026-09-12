<?php

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $company = DB::table('companies')->where('slug', 'restaurante-sol')->first();
        if ($company === null) {
            return;
        }

        $product = DB::table('products')
            ->where('company_id', $company->id)
            ->where('slug', 'n5-casa')
            ->first();
        $egg = DB::table('menu_components')
            ->where('company_id', $company->id)
            ->where('slug', 'ovo-frito')
            ->first();
        if ($product === null || $egg === null) {
            return;
        }

        $now = now();
        $group = DB::table('product_option_groups')
            ->where('product_id', $product->id)
            ->where('code', 'adicionais')
            ->first();
        $groupValues = [
            'company_id' => $company->id,
            'product_id' => $product->id,
            'code' => 'adicionais',
            'label' => 'Adicionais',
            'selection_mode' => ProductSelectionMode::Addon->value,
            'selection_actor' => ProductSelectionActor::Customer->value,
            'is_required' => false,
            'min_choices' => 0,
            'max_choices' => 1,
            'min_quantity' => 0,
            'max_quantity' => null,
            'same_component_only' => true,
            'included_in_base_price' => false,
            'display_order' => 40,
            'updated_at' => $now,
        ];
        $groupId = $group === null
            ? (int) DB::table('product_option_groups')->insertGetId([...$groupValues, 'created_at' => $now])
            : (int) $group->id;
        if ($group !== null) {
            DB::table('product_option_groups')->where('id', $groupId)->update($groupValues);
        }

        DB::table('product_group_components')->updateOrInsert(
            ['product_option_group_id' => $groupId, 'menu_component_id' => $egg->id],
            [
                'price_delta_cents' => 200,
                'final_price_cents' => null,
                'included_quantity' => null,
                'is_default' => false,
                'is_active' => true,
                'requires_confirmation' => false,
                'display_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        $company = DB::table('companies')->where('slug', 'restaurante-sol')->first();
        if ($company === null) {
            return;
        }

        $product = DB::table('products')
            ->where('company_id', $company->id)
            ->where('slug', 'n5-casa')
            ->first();
        if ($product === null) {
            return;
        }

        $group = DB::table('product_option_groups')
            ->where('product_id', $product->id)
            ->where('code', 'adicionais')
            ->first();
        if ($group === null) {
            return;
        }

        $eggId = DB::table('menu_components')
            ->where('company_id', $company->id)
            ->where('slug', 'ovo-frito')
            ->value('id');
        if ($eggId !== null) {
            DB::table('product_group_components')
                ->where('product_option_group_id', $group->id)
                ->where('menu_component_id', $eggId)
                ->delete();
        }

        if (! DB::table('product_group_components')->where('product_option_group_id', $group->id)->exists()
            && ! DB::table('product_group_products')->where('product_option_group_id', $group->id)->exists()) {
            DB::table('product_option_groups')->where('id', $group->id)->delete();
        }
    }
};
