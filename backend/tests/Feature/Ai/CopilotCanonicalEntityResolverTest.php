<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\CopilotCanonicalEntityResolver;
use Tests\TestCase;

class CopilotCanonicalEntityResolverTest extends TestCase
{
    public function test_longest_entities_consume_their_spans_before_shorter_entities(): void
    {
        $entities = [
            ['id' => 1, 'name' => 'Almôndega', 'type' => 'meat'],
            ['id' => 2, 'name' => 'Porco', 'type' => 'meat'],
            ['id' => 3, 'name' => 'Bisteca de porco na chapa', 'type' => 'meat'],
            ['id' => 4, 'name' => 'Frango', 'type' => 'meat'],
            ['id' => 5, 'name' => 'Filé de frango na chapa', 'type' => 'meat'],
        ];

        $result = app(CopilotCanonicalEntityResolver::class)->resolve(
            'almôndega e bisteca de porco na chapa e filé de frango na chapa',
            $entities,
        );

        $this->assertSame([1, 3, 5], array_column($result['resolved'], 'canonical_id'));
        $this->assertSame([], $result['ambiguous']);
        $this->assertSame('customer_explicit', data_get($result, 'resolved.1.provenance'));
        $this->assertGreaterThan(data_get($result, 'resolved.1.char_start'), data_get($result, 'resolved.1.char_end'));
        $this->assertFalse($this->hasOverlappingSpans($result['consumed_spans']));
    }

    public function test_longest_match_is_generic_for_components_and_products(): void
    {
        $entities = [
            ['id' => 10, 'name' => 'Tomate'],
            ['id' => 11, 'name' => 'Repolho com tomate'],
            ['id' => 12, 'name' => 'Arroz branco', 'aliases' => ['arroz']],
            ['id' => 13, 'name' => 'Arroz amarelo'],
            ['id' => 14, 'name' => 'Coca'],
            ['id' => 15, 'name' => 'Coca Zero'],
        ];

        $result = app(CopilotCanonicalEntityResolver::class)->resolve(
            'repolho com tomate arroz amarelo e coca zero',
            $entities,
        );

        $this->assertSame([11, 13, 15], array_column($result['resolved'], 'canonical_id'));
        $this->assertFalse($this->hasOverlappingSpans($result['consumed_spans']));
    }

    public function test_same_alias_for_multiple_entities_remains_an_explicit_candidate(): void
    {
        $result = app(CopilotCanonicalEntityResolver::class)->resolve('frango', [
            ['id' => 20, 'name' => 'Frango ao molho', 'aliases' => ['frango']],
            ['id' => 21, 'name' => 'Filé de frango', 'aliases' => ['frango']],
        ]);

        $this->assertSame([], $result['resolved']);
        $this->assertSame([20, 21], data_get($result, 'ambiguous.0.candidate_ids'));
        $this->assertSame('candidate', data_get($result, 'ambiguous.0.status'));
    }

    /** @param list<array{token_start:int,token_end:int}> $spans */
    private function hasOverlappingSpans(array $spans): bool
    {
        foreach ($spans as $leftIndex => $left) {
            foreach ($spans as $rightIndex => $right) {
                if ($leftIndex >= $rightIndex) {
                    continue;
                }
                if ($left['token_start'] <= $right['token_end'] && $left['token_end'] >= $right['token_start']) {
                    return true;
                }
            }
        }

        return false;
    }
}
