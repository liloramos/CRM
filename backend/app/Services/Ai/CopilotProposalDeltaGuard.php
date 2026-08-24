<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Str;

final class CopilotProposalDeltaGuard
{
    public function __construct(private readonly CopilotMenuAliasResolver $aliases) {}

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed> */
    public function restrict(Company $company, array $safe, array $context): array
    {
        if (! is_array(data_get($context, 'active_order'))) {
            return $safe;
        }

        $latest = $this->latestInbound($context);
        if ($latest === '') {
            return $safe;
        }

        if ($this->isChangeRequest($latest)) {
            $changeItems = collect(data_get($safe, 'draft_order.items', []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->filter(fn (array $item): bool => $this->isRequestedNow($company, $item, $latest))
                ->values();
            $changeItem = $changeItems->first();
            $changeProduct = $changeItem === null ? null : $this->productFor($company, $changeItem);
            $hasSelection = $changeItem !== null && $this->hasMeatSelection($changeItem);
            $activeItems = collect(data_get($context, 'active_order.items', []));
            $candidates = $changeItem === null ? collect() : $activeItems->filter(fn (array $item): bool => (int) ($item['product_id'] ?? 0) === (int) ($changeItem['menu_item_id'] ?? 0));
            $missing = array_values(array_filter(data_get($safe, 'missing_information', []), fn (array $entry): bool => ! ($hasSelection && strtoupper((string) ($entry['code'] ?? '')) === 'CARNE')));
            if ($hasSelection && $candidates->count() > 1 && ! $this->areSemanticallyEquivalent($candidates->all())) {
                $missing[] = ['code' => 'TARGET_ORDER_ITEM', 'label' => 'Item do pedido'];
            }

            return [
                ...$safe,
                'intent' => 'ORDER_CHANGE',
                'draft_order' => [...(is_array($safe['draft_order'] ?? null) ? $safe['draft_order'] : []), 'items' => [], 'change_request' => $changeItem === null ? null : [
                    'product_id' => (int) ($changeItem['menu_item_id'] ?? 0),
                    'product_slug' => (string) ($changeItem['menu_item_slug'] ?? ''),
                    'product_name' => (string) ($changeProduct?->name ?? $changeItem['product_name'] ?? $changeItem['menu_item_slug'] ?? 'item'),
                    'to_selections' => $changeItem['selections'] ?? [],
                    'target_item_ids' => $candidates->pluck('id')->values()->all(),
                    'target_items_equivalent' => $candidates->count() > 1 && $this->areSemanticallyEquivalent($candidates->all()),
                ]],
                'missing_information' => $this->dedupeMissing($missing),
                'warnings' => $this->appendWarning($safe, 'ORDER_CHANGE_REQUIRES_REVIEW', 'A alteração de um item existente precisa de revisão humana.'),
            ];
        }

        $items = collect(data_get($safe, 'draft_order.items', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->filter(fn (array $item): bool => $this->isRequestedNow($company, $item, $latest))
            ->values()
            ->all();

        return [...$safe, 'draft_order' => [...(is_array($safe['draft_order'] ?? null) ? $safe['draft_order'] : []), 'items' => $items]];
    }

    /** @param array<string,mixed> $item */
    private function isRequestedNow(Company $company, array $item, string $latest): bool
    {
        $product = $this->productFor($company, $item);

        return $product !== null && $this->aliases->isExplicitlyReferenced($product, [[
            'direction' => 'inbound',
            'type' => 'text',
            'body' => $latest,
        ]]);
    }

    /** @param array<string,mixed> $item */
    private function productFor(Company $company, array $item): ?Product
    {
        return $this->aliases->resolve(
            $company,
            isset($item['menu_item_id']) ? (int) $item['menu_item_id'] : null,
            (string) ($item['menu_item_slug'] ?? ''),
        );
    }

    /** @param array<string,mixed> $item */
    private function hasMeatSelection(array $item): bool
    {
        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];

        return collect($selections['meats'] ?? $selections['meat'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->isNotEmpty();
    }

    /** @param list<array<string,mixed>> $items */
    private function areSemanticallyEquivalent(array $items): bool
    {
        if (count($items) < 2) {
            return true;
        }

        $fingerprints = array_map(fn (array $item): string => $this->fingerprint($item), $items);

        return count(array_unique($fingerprints)) === 1;
    }

    /** @param array<string,mixed> $item */
    private function fingerprint(array $item): string
    {
        $options = collect($item['options'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option))
            ->map(fn (array $option): array => [
                'name' => (string) ($option['name'] ?? ''),
                'group' => (string) ($option['group_code'] ?? ''),
                'quantity' => (int) ($option['quantity'] ?? 1),
                'metadata' => $this->normalize($option['metadata'] ?? []),
            ])
            ->sortBy(fn (array $option): string => json_encode($option, JSON_THROW_ON_ERROR))
            ->values()
            ->all();

        return json_encode([
            'product_id' => (int) ($item['product_id'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'meat_mode' => (string) ($item['meat_mode'] ?? 'traditional'),
            'selected_components' => $this->normalize($item['selected_components'] ?? []),
            'removed_ingredients' => $this->normalize($item['removed_ingredients'] ?? []),
            'options' => $options,
            'item_notes' => trim((string) ($item['item_notes'] ?? '')),
            'beneficiary_name' => trim((string) ($item['beneficiary_name'] ?? '')),
        ], JSON_THROW_ON_ERROR);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn (mixed $entry): mixed => $this->normalize($entry), $value);
            sort($normalized);

            return $normalized;
        }

        ksort($value);

        return array_map(fn (mixed $entry): mixed => $this->normalize($entry), $value);
    }

    /** @param list<array<string,mixed>> $missing @return list<array<string,mixed>> */
    private function dedupeMissing(array $missing): array
    {
        return collect($missing)->unique(fn (array $entry): string => strtoupper((string) ($entry['code'] ?? '')))->values()->all();
    }

    /** @param array<string,mixed> $context */
    private function latestInbound(array $context): string
    {
        foreach (array_reverse(data_get($context, 'messages', [])) as $message) {
            if (($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text') {
                return (string) ($message['body'] ?? '');
            }
        }

        return '';
    }

    private function isChangeRequest(string $text): bool
    {
        $text = Str::of($text)->ascii()->lower()->squish()->toString();

        return preg_match('/\b(troca|troque|alter[ae]|muda|mude|substitui[ar]?|tir[ae]|remove[ar]?)\b/', $text) === 1;
    }

    /** @param array<string,mixed> $safe @return list<array<string,mixed>> */
    private function appendWarning(array $safe, string $code, string $message): array
    {
        $warnings = array_values(data_get($safe, 'warnings', []));
        if (! collect($warnings)->contains(fn (array $warning): bool => ($warning['code'] ?? null) === $code)) {
            $warnings[] = compact('code', 'message');
        }

        return $warnings;
    }
}
