<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Resolves catalog entities against one shared set of occupied token spans.
 * A longer canonical identity always reserves its span before a shorter alias.
 */
final class CopilotCanonicalEntityResolver
{
    /**
     * @param  list<array<string,mixed>>  $entities
     * @return array{resolved:list<array<string,mixed>>,ambiguous:list<array<string,mixed>>,consumed_spans:list<array<string,int>>}
     */
    public function resolve(string $text, array $entities, string $provenance = 'customer_explicit'): array
    {
        $tokens = $this->tokens($text);
        if ($tokens === []) {
            return ['resolved' => [], 'ambiguous' => [], 'consumed_spans' => []];
        }

        $matches = [];
        foreach ($entities as $entity) {
            $id = (int) ($entity['id'] ?? $entity['canonical_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $identities = collect([
                ['value' => $entity['name'] ?? '', 'source' => 'canonical_name'],
                ['value' => $entity['display_name'] ?? '', 'source' => 'canonical_display_name'],
                ['value' => $entity['slug'] ?? '', 'source' => 'canonical_slug'],
                ...collect((array) ($entity['aliases'] ?? []))->map(fn (mixed $alias): array => is_array($alias) ? [
                    'value' => (string) ($alias['value'] ?? ''),
                    'source' => (string) ($alias['source'] ?? 'catalog_alias'),
                ] : [
                    'value' => (string) $alias,
                    'source' => 'catalog_alias',
                ])->all(),
            ])->filter(fn (array $identity): bool => trim((string) $identity['value']) !== '')
                ->sortByDesc(fn (array $identity): int => $this->identityPriority((string) $identity['source']))
                ->unique(fn (array $identity): string => implode('|', $this->identityTokens((string) $identity['value'])))
                ->values();

            foreach ($identities as $identity) {
                $needle = $this->identityTokens((string) $identity['value']);
                if ($needle === []) {
                    continue;
                }
                $count = count($needle);
                for ($start = 0; $start <= count($tokens) - $count; $start++) {
                    if (array_slice(array_column($tokens, 'normalized'), $start, $count) !== $needle) {
                        continue;
                    }
                    $endToken = $start + $count - 1;
                    $charStart = $tokens[$start]['start'];
                    $charEnd = $tokens[$endToken]['end'];
                    $matches[] = [
                        'canonical_id' => $id,
                        'canonical_name' => (string) ($entity['name'] ?? $entity['display_name'] ?? ''),
                        'entity_type' => (string) ($entity['type'] ?? $entity['entity_type'] ?? ''),
                        'available_today' => (bool) ($entity['available_today'] ?? true),
                        'token_start' => $start,
                        'token_end' => $endToken,
                        'char_start' => $charStart,
                        'char_end' => $charEnd,
                        'matched_text' => substr($text, $charStart, $charEnd - $charStart),
                        'matched_identity' => (string) $identity['value'],
                        'identity_source' => (string) $identity['source'],
                        'identity_priority' => $this->identityPriority((string) $identity['source']),
                        'provenance' => $provenance,
                        'token_count' => $count,
                        'identity_length' => strlen($this->key((string) $identity['value'])),
                    ];
                }
            }
        }

        usort($matches, fn (array $left, array $right): int => [$right['token_count'], $right['identity_length'], $right['identity_priority'], -$right['token_start']]
            <=> [$left['token_count'], $left['identity_length'], $left['identity_priority'], -$left['token_start']]);

        $resolved = [];
        $ambiguous = [];
        $occupied = [];
        foreach ($matches as $match) {
            if ($this->overlaps($match, $occupied)) {
                continue;
            }
            $sameSpan = collect($matches)->filter(fn (array $candidate): bool => $candidate['token_start'] === $match['token_start']
                && $candidate['token_end'] === $match['token_end']
                && $this->key((string) $candidate['matched_identity']) === $this->key((string) $match['matched_identity']))
                ->unique('canonical_id')
                ->values();
            $availableSameSpan = $sameSpan->where('available_today', true)->values();
            if ($availableSameSpan->isNotEmpty()) {
                $sameSpan = $availableSameSpan;
                $match = $availableSameSpan->first();
            }
            $highestPriority = (int) $sameSpan->max('identity_priority');
            $preferredSameSpan = $sameSpan->where('identity_priority', $highestPriority)->values();
            if ($preferredSameSpan->isNotEmpty()) {
                $sameSpan = $preferredSameSpan;
                $match = $preferredSameSpan->first();
            }
            $occupied[] = ['token_start' => $match['token_start'], 'token_end' => $match['token_end']];
            if ($sameSpan->count() > 1) {
                $ambiguous[] = [
                    'matched_text' => $match['matched_text'],
                    'token_start' => $match['token_start'],
                    'token_end' => $match['token_end'],
                    'char_start' => $match['char_start'],
                    'char_end' => $match['char_end'],
                    'candidate_ids' => $sameSpan->pluck('canonical_id')->map(fn (mixed $id): int => (int) $id)->all(),
                    'candidate_names' => $sameSpan->pluck('canonical_name')->filter()->unique()->values()->all(),
                    'provenance' => $provenance,
                    'status' => 'candidate',
                ];

                continue;
            }
            unset($match['token_count'], $match['identity_length'], $match['identity_priority']);
            $resolved[] = $match;
        }

        return [
            'resolved' => collect($resolved)->unique('canonical_id')->sortBy('token_start')->values()->all(),
            'ambiguous' => $ambiguous,
            'consumed_spans' => $occupied,
        ];
    }

    /** @return list<array{normalized:string,start:int,end:int}> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\pL\pN]+/u', $text, $matches, PREG_OFFSET_CAPTURE);

        return collect($matches[0] ?? [])->map(fn (array $match): array => [
            'normalized' => $this->key((string) $match[0]),
            'start' => (int) $match[1],
            'end' => (int) $match[1] + strlen((string) $match[0]),
        ])->filter(fn (array $token): bool => $token['normalized'] !== '')->values()->all();
    }

    /** @return list<string> */
    private function identityTokens(string $identity): array
    {
        preg_match_all('/[\pL\pN]+/u', $identity, $matches);

        return collect($matches[0] ?? [])->map(fn (string $token): string => $this->key($token))->filter()->values()->all();
    }

    /** @param list<array{token_start:int,token_end:int}> $occupied */
    private function overlaps(array $match, array $occupied): bool
    {
        return collect($occupied)->contains(fn (array $span): bool => $match['token_start'] <= $span['token_end'] && $match['token_end'] >= $span['token_start']);
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function identityPriority(string $source): int
    {
        return match ($source) {
            'canonical_name', 'canonical_display_name', 'canonical_slug' => 3,
            'catalog_alias' => 2,
            default => 1,
        };
    }
}
