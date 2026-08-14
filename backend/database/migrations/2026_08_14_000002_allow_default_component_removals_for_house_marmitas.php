<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereIn('slug', ['n5-casa', 'n8-casa'])
            ->orderBy('id')
            ->each(function (object $product): void {
                $rules = json_decode((string) ($product->composition_rules ?? '{}'), true) ?: [];

                if (($rules['fixed_components_removable'] ?? null) === true) {
                    return;
                }

                $rules['fixed_components_removable'] = true;

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'composition_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('products')
            ->whereIn('slug', ['n5-casa', 'n8-casa'])
            ->orderBy('id')
            ->each(function (object $product): void {
                $rules = json_decode((string) ($product->composition_rules ?? '{}'), true) ?: [];

                if (($rules['fixed_components_removable'] ?? null) !== true) {
                    return;
                }

                $rules['fixed_components_removable'] = false;

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'composition_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }
};
