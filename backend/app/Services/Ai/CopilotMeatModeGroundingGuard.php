<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Support\Str;

final class CopilotMeatModeGroundingGuard
{
    /**
     * @param  array<string,mixed>  $selections
     * @param  list<array<string,mixed>>  $messages
     * @return array{selections:array<string,mixed>,warnings:list<array{code:string,message:string}>}
     */
    public function ground(Product $product, array $selections, array $messages): array
    {
        $text = $this->customerText($messages);
        $supportsBeefModes = in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true);
        $supportsWithoutMeat = $supportsBeefModes
            ? true
            : in_array('carne', data_get($product->composition_rules, 'allow_no_meat_group_codes', []), true);
        $withoutMeat = preg_match('/\b(?:sem\s+carne|nao\s+(?:quero|quero)\s+carne|pode\s+vir\s+sem\s+carne)\b/', $text) === 1;

        if (! $supportsBeefModes) {
            if ($withoutMeat && $supportsWithoutMeat) {
                return ['selections' => [...$selections, 'meat_mode' => 'none', 'meat' => null, 'meats' => []], 'warnings' => []];
            }

            return ['selections' => $selections, 'warnings' => $withoutMeat ? [[
                'code' => 'DOMAIN_SELECTION_REJECTED',
                'message' => 'Este produto nao permite a escolha sem carne.',
            ]] : []];
        }

        $negatedOnlyBeef = preg_match('/\bnao\s+(?:quero\s+)?(?:so|somente|apenas)\s+bife(?:s)?\b/', $text) === 1;
        $onlyBeef = preg_match('/\b(?:so|somente|apenas)\s+bife(?:s)?\b/', $text) === 1 && ! $negatedOnlyBeef;
        $extraBeef = preg_match('/\b(?:bife\s+(?:extra|adicional|por\s+fora)|mais\s+um\s+bife)\b/', $text) === 1;

        if ($withoutMeat) {
            if (! $supportsWithoutMeat) {
                return [
                    'selections' => $selections,
                    'warnings' => [[
                        'code' => 'DOMAIN_SELECTION_REJECTED',
                        'message' => 'Este produto nao permite a escolha sem carne.',
                    ]],
                ];
            }

            if ($onlyBeef || $extraBeef || $this->hasExplicitTraditionalMeat($text)) {
                return [
                    'selections' => [...$selections, 'meat_mode' => null, 'beef_variant' => null, 'meat' => null, 'meats' => [], 'extra_beef' => 0],
                    'warnings' => [[
                        'code' => 'CONFLICTING_MEAT_REQUEST',
                        'message' => 'Sem carne nao pode ser combinado com outra escolha de carne.',
                    ]],
                ];
            }

            return [
                'selections' => [...$selections, 'meat_mode' => 'none', 'beef_variant' => null, 'meat' => null, 'meats' => [], 'extra_beef' => 0],
                'warnings' => [],
            ];
        }

        if ($negatedOnlyBeef && $this->key((string) ($selections['meat_mode'] ?? '')) === 'beefonly') {
            return [
                'selections' => [...$selections, 'meat_mode' => null, 'beef_variant' => null, 'meat' => null, 'meats' => [], 'extra_beef' => 0],
                'warnings' => [[
                    'code' => 'DOMAIN_SELECTION_REJECTED',
                    'message' => 'O modo somente bife proposto contradiz a mensagem do cliente.',
                ]],
            ];
        }

        if ($onlyBeef) {
            if ($this->hasExplicitTraditionalMeat($text)) {
                return [
                    'selections' => [...$selections, 'meat_mode' => null, 'beef_variant' => null, 'meat' => null, 'meats' => [], 'extra_beef' => 0],
                    'warnings' => [[
                        'code' => 'CONFLICTING_MEAT_REQUEST',
                        'message' => 'O pedido menciona somente bife e outra carne ao mesmo tempo.',
                    ]],
                ];
            }
            $hasContradictoryProposal = $this->hasTraditionalMeats($selections) || (int) ($selections['extra_beef'] ?? 0) > 0;

            return [
                'selections' => [...$selections, 'meat_mode' => 'beef_only', 'beef_variant' => 'bife', 'meat' => null, 'meats' => [], 'extra_beef' => 0],
                'warnings' => $hasContradictoryProposal ? [[
                    'code' => 'DOMAIN_SELECTION_REJECTED',
                    'message' => 'Somente bife substitui carnes tradicionais e nao aceita bife adicional.',
                ]] : [],
            ];
        }

        if ($extraBeef) {
            return ['selections' => [...$selections, 'meat_mode' => 'traditional', 'extra_beef' => 1], 'warnings' => []];
        }

        return ['selections' => $selections, 'warnings' => []];
    }

    /** @param list<array<string,mixed>> $messages */
    private function customerText(array $messages): string
    {
        return collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->map(fn (mixed $body): string => Str::of((string) $body)->ascii()->lower()->toString())
            ->implode(' ');
    }

    /** @param array<string,mixed> $selections */
    private function hasTraditionalMeats(array $selections): bool
    {
        $meats = $selections['meats'] ?? $selections['meat'] ?? [];

        return is_array($meats) ? $meats !== [] : filled($meats);
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function hasExplicitTraditionalMeat(string $text): bool
    {
        return preg_match('/\b(?:porco|frango|almondega|linguica|peixe)\b/', $text) === 1;
    }
}
