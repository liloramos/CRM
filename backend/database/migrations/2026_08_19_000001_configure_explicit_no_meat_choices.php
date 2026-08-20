<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Product::query()
            ->whereHas('company', fn ($query) => $query->where('slug', 'restaurante-sol'))
            ->whereIn('slug', ['n5-casa', 'n8-casa', 'n8-tradicional', 'n9-tradicional'])
            ->orderBy('id')
            ->each(function (Product $product): void {
                $rules = $product->composition_rules ?? [];

                if (in_array($product->slug, ['n5-casa', 'n8-casa'], true)) {
                    $rules['allow_no_meat_group_codes'] = collect($rules['allow_no_meat_group_codes'] ?? [])
                        ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
                        ->push('carne')
                        ->unique()
                        ->values()
                        ->all();
                } else {
                    $rules['traditional_meat_selection'] = [
                        ...(is_array($rules['traditional_meat_selection'] ?? null) ? $rules['traditional_meat_selection'] : []),
                        'min_types' => 1,
                        'max_types' => 2,
                        'allow_none' => true,
                    ];
                }

                $product->forceFill(['composition_rules' => $rules])->save();
            });
    }

    public function down(): void
    {
        Product::query()
            ->whereHas('company', fn ($query) => $query->where('slug', 'restaurante-sol'))
            ->whereIn('slug', ['n5-casa', 'n8-casa', 'n8-tradicional', 'n9-tradicional'])
            ->orderBy('id')
            ->each(function (Product $product): void {
                $rules = $product->composition_rules ?? [];
                if (in_array($product->slug, ['n5-casa', 'n8-casa'], true)) {
                    $codes = collect($rules['allow_no_meat_group_codes'] ?? [])
                        ->filter(fn (mixed $code): bool => is_string($code) && $code !== 'carne')
                        ->values()
                        ->all();

                    if ($codes === []) {
                        unset($rules['allow_no_meat_group_codes']);
                    } else {
                        $rules['allow_no_meat_group_codes'] = $codes;
                    }
                } else {
                    $selection = is_array($rules['traditional_meat_selection'] ?? null)
                        ? $rules['traditional_meat_selection']
                        : [];
                    unset($selection['allow_none']);

                    if ($selection === []) {
                        unset($rules['traditional_meat_selection']);
                    } else {
                        $rules['traditional_meat_selection'] = $selection;
                    }
                }

                $product->forceFill(['composition_rules' => $rules])->save();
            });
    }
};
