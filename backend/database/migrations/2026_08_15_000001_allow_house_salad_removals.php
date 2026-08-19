<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateRules([
            'n5-casa' => 'salada_casa',
            'n8-casa' => 'salada',
        ], true);
    }

    public function down(): void
    {
        $this->updateRules([
            'n5-casa' => 'salada_casa',
            'n8-casa' => 'salada',
        ], false);
    }

    /** @param array<string, string> $groupCodes */
    private function updateRules(array $groupCodes, bool $add): void
    {
        DB::table('products')
            ->whereIn('slug', array_keys($groupCodes))
            ->orderBy('id')
            ->each(function (object $product) use ($groupCodes, $add): void {
                $rules = json_decode((string) ($product->composition_rules ?? '{}'), true) ?: [];
                $existingCodes = is_array($rules['removable_group_codes'] ?? null)
                    ? $rules['removable_group_codes']
                    : [];
                $groupCode = $groupCodes[$product->slug];
                $nextCodes = $add
                    ? array_values(array_unique([...$existingCodes, $groupCode]))
                    : array_values(array_filter($existingCodes, fn (mixed $code): bool => $code !== $groupCode));

                if ($nextCodes === $existingCodes) {
                    return;
                }

                $rules['removable_group_codes'] = $nextCodes;

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'composition_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }
};
