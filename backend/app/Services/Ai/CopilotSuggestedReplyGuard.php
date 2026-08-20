<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotSuggestedReplyGuard
{
    public function __construct(private readonly CopilotProductClarificationBuilder $clarifications) {}

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed> */
    public function restrict(array $safe, array $context): array
    {
        $clarification = $this->clarifications->forSafe($safe, $context);
        if ($clarification !== null) {
            return [...$safe, 'clarification' => $clarification, 'suggested_reply' => $this->clarifications->reply($clarification)];
        }

        $reply = trim((string) ($safe['suggested_reply'] ?? ''));

        if (data_get($safe, 'draft_order.items', []) === [] && $this->assumesResolvedMenuChoice($reply)) {
            return [...$safe, 'clarification' => null, 'suggested_reply' => $this->safeFallback($safe)];
        }

        if ($reply === '' || ! $this->contradictsResolvedFacts($reply, $safe)) {
            return [...$safe, 'clarification' => null];
        }

        return [...$safe, 'clarification' => null, 'suggested_reply' => $this->safeFallback($safe)];
    }

    /** @param array<string,mixed> $safe */
    private function contradictsResolvedFacts(string $reply, array $safe): bool
    {
        $text = Str::of($reply)->ascii()->lower()->toString();
        if (preg_match('/\b(registrei|criei|adicionei(?:\s+ao)?\s+pedido|confirmei\s+pagamento)\b/', $text)) {
            return true;
        }

        $items = array_values(data_get($safe, 'draft_order.items', []));
        $productSlugs = array_filter(array_map(fn (mixed $item): string => is_array($item) ? (string) ($item['menu_item_slug'] ?? '') : '', $items));
        if (array_intersect($productSlugs, ['n8-casa', 'n8-tradicional']) !== []
            && preg_match('/n8.*(?:casa.*livre|livre.*casa)/', $text)) {
            return true;
        }

        if ($items !== []
            && collect($items)->every(fn (mixed $item): bool => is_array($item) && (int) ($item['quantity'] ?? 0) > 0)
            && preg_match('/\b(quantos|quantas|quantidade)\b/', $text)) {
            return true;
        }

        $missingCodes = array_map(
            fn (mixed $missing): string => strtoupper((string) data_get($missing, 'code', '')),
            data_get($safe, 'missing_information', []),
        );
        if (! in_array('SALADA', $missingCodes, true)
            && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*salada\b/', $text) === 1) {
            return true;
        }

        return ($this->hasSelection($items, ['meat', 'meats']) && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*carne/', $text))
            || ($this->hasSelection($items, ['salad', 'salads', 'salada', 'saladas']) && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*salada/', $text));
    }

    private function assumesResolvedMenuChoice(string $reply): bool
    {
        $text = Str::of($reply)->ascii()->lower()->toString();

        return preg_match('/\bqual(?:\s+e)?\s+a?s?\s*(?:carne|salada)\b/', $text) === 1;
    }

    /** @param list<mixed> $items @param list<string> $keys */
    private function hasSelection(array $items, array $keys): bool
    {
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['selections'] ?? null)) {
                continue;
            }

            foreach ($keys as $key) {
                $value = $item['selections'][$key] ?? null;
                if ((is_array($value) && $value !== []) || (is_string($value) && $value !== '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $safe */
    private function safeFallback(array $safe): string
    {
        $warnings = array_column(data_get($safe, 'warnings', []), 'code');
        $missing = array_column(data_get($safe, 'missing_information', []), 'code');

        if (in_array('CONFLICTING_MEAT_REQUEST', $warnings, true)) {
            return 'Você quer somente bife ou porco com bife adicional?';
        }
        if (in_array('CARNE', $missing, true)) {
            return 'Qual carne o cliente deseja?';
        }
        if (in_array('SALADA', $missing, true)) {
            return 'Qual salada o cliente deseja?';
        }
        if (in_array('ADDRESS', $missing, true)) {
            return 'Qual é o endereço para a entrega?';
        }

        return '';
    }
}
