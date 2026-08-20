<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Str;

final class CopilotProductGroundingGuard
{
    public function __construct(private readonly CopilotMenuAliasResolver $aliases) {}

    /** @param list<array<string,mixed>> $messages */
    public function isGrounded(Product $product, array $messages): bool
    {
        return $this->aliases->isExplicitlyReferenced($product, $messages);
    }

    /** @param list<array<string,mixed>> $messages */
    public function hasGroundedProductReference(Company $company, array $messages): bool
    {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('is_available_by_default', true)
            ->cursor()
            ->contains(fn (Product $product): bool => $this->isGrounded($product, $messages));
    }

    /** @param list<array<string,mixed>> $messages @return array<string,mixed>|null */
    public function recoverN8Traditional(Company $company, array $messages): ?array
    {
        $text = $this->latestN8Turn($messages);
        if ($text === null || str_contains($text, 'n8casa')) {
            return null;
        }

        $product = $this->aliases->resolve($company, null, 'n8');
        if (! $product?->is_active || ! $product->is_available_by_default) {
            return null;
        }

        return [
            'menu_item_id' => $product->id,
            'menu_item_slug' => $product->slug,
            'quantity' => 1,
            'selections' => ['meats' => $this->explicitN8Meats($text)],
            'removed_components' => [],
            'item_notes' => '',
        ];
    }

    /** @param array<string,mixed> $item @param list<array<string,mixed>> $messages @return array<string,mixed> */
    public function enrichN8Traditional(Product $product, array $item, array $messages): array
    {
        $text = $this->latestN8Turn($messages);
        if ($product->menu_rule_code !== 'n8_tradicional' || $text === null || str_contains($text, 'n8casa')) {
            return $item;
        }

        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
        if (str_contains($text, 'sem carne') || preg_match('/\bnao\s+(?:quero|quero)\s+carne\b/', $text) === 1) {
            return [...$item, 'selections' => [...$selections, 'meat_mode' => 'none', 'meat' => null, 'meats' => [], 'extra_beef' => 0]];
        }
        $meats = is_array($selections['meats'] ?? null) ? $selections['meats'] : [];
        foreach ($this->explicitN8Meats($text) as $meat) {
            if (! in_array($meat, $meats, true)) {
                $meats[] = $meat;
            }
        }

        return [...$item, 'selections' => [...$selections, 'meats' => $meats]];
    }

    /** @param list<array<string,mixed>> $messages */
    private function latestN8Turn(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $text = $this->key((string) ($message['body'] ?? ''));
            if (str_contains($text, 'n8')) {
                return $text;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function explicitN8Meats(string $text): array
    {
        return preg_match('/\bporco\b/', $text) === 1 ? ['porco'] : [];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->toString();
    }
}
