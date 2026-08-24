<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CustomerFacingProductPresenter
{
    /** @param array<string,mixed> $item @param array<string,mixed> $context */
    public function name(array $item, array $context): string
    {
        $menuProduct = collect(data_get($context, 'menu', []))
            ->first(fn (mixed $product): bool => is_array($product)
                && ((int) ($product['id'] ?? 0) === (int) ($item['menu_item_id'] ?? 0)
                    || (string) ($product['slug'] ?? '') === (string) ($item['menu_item_slug'] ?? '')));

        $name = (string) data_get($menuProduct, 'name', (string) ($item['menu_item_slug'] ?? 'item'));
        if (data_get($menuProduct, 'rule') === 'n8_tradicional') {
            $price = (int) data_get($menuProduct, 'base_price_cents', 0);
            if ($price > 0) {
                return 'N8 de R$ '.$this->wholeReais($price);
            }
        }

        return $name;
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $context */
    public function description(array $item, array $context): string
    {
        $parts = collect($item['resolved_selections'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->humanLabel($value))
            ->unique(fn (string $value): string => Str::of($value)->ascii()->lower()->squish()->toString())
            ->values();
        $meats = collect(data_get($item, 'selections.meats', []))
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->humanLabel($value))
            ->unique(fn (string $value): string => Str::of($value)->ascii()->lower()->squish()->toString());
        $parts = $parts->merge($meats)->unique(fn (string $value): string => Str::of($value)->ascii()->lower()->squish()->toString());

        return $parts->implode(', ');
    }

    private function humanLabel(string $value): string
    {
        return match (Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString()) {
            'puredebatata' => 'Purê de batata',
            'feijao' => 'Feijão',
            'macarrao' => 'Macarrão',
            'almondega', 'almondegas' => 'Almôndega',
            default => $value,
        };
    }

    private function wholeReais(int $cents): string
    {
        return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.');
    }
}
