<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class CopilotProductGroundingGuard
{
    public function __construct(
        private readonly CopilotMenuAliasResolver $aliases,
        private readonly StructuredProductConfigurationService $productConfiguration,
        private readonly CopilotProductEligibility $eligibility,
        private readonly CopilotDailyMeatEntityResolver $meats,
    ) {}

    /** @param list<array<string,mixed>> $messages */
    public function isGrounded(Product $product, array $messages): bool
    {
        if ($this->aliases->isExplicitlyReferenced($product, $messages)) {
            return true;
        }

        return $product->menu_rule_code === 'n8_tradicional'
            && $this->isExplicitTraditionalN8Segment($this->productSegment($messages, 'n8'));
    }

    /** @param list<array<string,mixed>> $messages */
    public function hasGroundedProductReference(Company $company, array $messages): bool
    {
        return $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('is_available_by_default', true)
            ->cursor()
            ->contains(fn (Product $product): bool => $this->isGrounded($product, $messages));
    }

    /** @param list<array<string,mixed>> $messages @return array<string,mixed>|null */
    public function recoverN8Traditional(Company $company, array $messages, ?CarbonInterface $date = null): ?array
    {
        $text = $this->productSegment($messages, 'n8');
        if (! $this->isExplicitTraditionalN8Segment($text)) {
            return null;
        }

        $product = $this->aliases->resolve($company, null, 'n8-tradicional');
        if (! $product?->is_active || ! $product->is_available_by_default) {
            return null;
        }

        return [
            'menu_item_id' => $product->id,
            'menu_item_slug' => $product->slug,
            'quantity' => 1,
            'selections' => ['meats' => $this->meats->names($company, $product, $date ?? CarbonImmutable::today(), $this->customerText($messages))],
            'removed_components' => [],
            'item_notes' => '',
        ];
    }

    /** @param array<string,mixed> $item @param list<array<string,mixed>> $messages @return array<string,mixed> */
    public function enrichN8Traditional(Company $company, Product $product, CarbonInterface $date, array $item, array $messages): array
    {
        $text = $this->productSegment($messages, 'n8');
        if ($product->menu_rule_code !== 'n8_tradicional' || $text === null || str_contains($this->key($text), 'n8casa')) {
            return $item;
        }

        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
        if (str_contains($text, 'sem carne') || preg_match('/\bnao\s+(?:quero|quero)\s+carne\b/', $text) === 1) {
            return [...$item, 'selections' => [...$selections, 'meat_mode' => 'none', 'meat' => null, 'meats' => [], 'extra_beef' => 0]];
        }
        $meats = collect((array) ($selections['meats'] ?? []));
        foreach ($this->meats->names($company, $product, $date, $this->customerText($messages)) as $meat) {
            if (! $meats->contains(fn (mixed $existing): bool => is_string($existing) && $this->key($existing) === $this->key($meat))) {
                $meats->push($meat);
            }
        }

        return [...$item, 'selections' => [...$selections, 'meats' => $meats->values()->all()]];
    }

    /** @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    public function recoverExplicitItems(Company $company, array $messages, ?CarbonInterface $date = null): array
    {
        $items = [];
        $date ??= CarbonImmutable::today();
        $n8 = $this->recoverN8Traditional($company, $messages, $date);
        if ($n8 !== null) {
            $items[] = $n8;
        }

        $n5Text = $this->productSegment($messages, 'n5');
        if ($n5Text !== null) {
            $n5 = $this->aliases->resolve($company, null, 'n5');
            if ($n5?->is_active && $n5->is_available_by_default) {
                $items[] = [
                    'menu_item_id' => $n5->id,
                    'menu_item_slug' => $n5->slug,
                    'quantity' => 1,
                    'selections' => ['meats' => $this->meats->names($company, $n5, $date, $this->customerText($messages))],
                    'removed_components' => [],
                    'item_notes' => '',
                ];
            }
        }

        $waterText = $this->customerText($messages);
        if (preg_match('/\bagua\s+sem\s+gas\b/', $waterText) === 1) {
            $water = $this->aliases->resolve($company, null, 'agua sem gas');
            if ($water?->is_active && $this->productConfiguration->isSellable($water, $company, $date ?? CarbonImmutable::today())) {
                $items[] = [
                    'menu_item_id' => $water->id,
                    'menu_item_slug' => $water->slug,
                    'quantity' => 1,
                    'selections' => [],
                    'removed_components' => [],
                    'item_notes' => '',
                ];
            }
        }

        return collect($items)->unique('menu_item_id')->values()->all();
    }

    /** @param list<array<string,mixed>> $items @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    public function orderByExplicitReferences(Company $company, array $items, array $messages): array
    {
        $positions = array_flip($this->aliases->explicitlyReferencedProductIds($company, $messages));

        return collect($items)
            ->map(fn (array $item, int $index): array => [
                'item' => $item,
                'position' => $positions[(int) ($item['menu_item_id'] ?? 0)] ?? PHP_INT_MAX,
                'index' => $index,
            ])
            ->sortBy(fn (array $entry): string => sprintf('%020d:%020d', $entry['position'], $entry['index']))
            ->pluck('item')
            ->values()
            ->all();
    }

    /** @param list<array<string,mixed>> $messages */
    public function selectionText(Product $product, array $messages): string
    {
        $code = match ($product->menu_rule_code) {
            'n8_tradicional', 'n8_casa' => 'n8',
            'n5_casa' => 'n5',
            default => null,
        };

        return $code !== null ? ($this->productSegment($messages, $code) ?? $this->customerText($messages)) : $this->customerText($messages);
    }

    /** @param list<array<string,mixed>> $messages */
    private function productSegment(array $messages, string $productCode): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $text = Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->squish()->toString();
            preg_match_all('/\\bn(?:5|8)(?:\\s*(?:casa|livre|tradicional)|casa|livre|tradicional)?\\b/', $text, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $index => [$match, $offset]) {
                if (! str_starts_with($match, $productCode)) {
                    continue;
                }

                $nextOffset = $matches[0][$index + 1][1] ?? strlen($text);

                return trim(substr($text, $offset, $nextOffset - $offset));
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $messages */
    private function customerText(array $messages): string
    {
        return collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->map(fn (mixed $body): string => Str::of((string) $body)->ascii()->lower()->squish()->toString())
            ->implode(' ');
    }

    private function isExplicitTraditionalN8Segment(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $key = $this->key($text);

        return str_contains($key, 'n8livre')
            || str_contains($key, 'n8tradicional')
            || preg_match('/\bn8\s+(?:so|somente|apenas)\s+bife\b/', $text) === 1;
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
