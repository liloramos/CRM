<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\CopilotEvaluationScorer;
use Tests\TestCase;

class CopilotEvaluationScorerTest extends TestCase
{
    public function test_it_ignores_only_neutral_structured_output_defaults(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case(['meat_mode' => 'beef_only']),
            $this->analysis(['meat' => null, 'meats' => [], 'meat_mode' => 'beef_only', 'beef_variant' => null, 'extra_beef' => 0, 'salada' => null]),
        );

        $this->assertTrue($scores['selections']);
    }

    public function test_it_keeps_non_neutral_selection_differences_strict(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case(['meat' => 'porco']),
            $this->analysis(['meat' => 'frango']),
        );

        $this->assertFalse($scores['selections']);
    }

    public function test_traditional_mode_is_redundant_when_traditional_meats_are_materialized(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case(['meats' => ['porco']]),
            $this->analysis(['meats' => ['Porco'], 'meat_mode' => 'traditional']),
        );

        $this->assertTrue($scores['selections']);
    }

    public function test_traditional_mode_is_neutral_without_concrete_meats_or_extra_beef(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case([]),
            $this->analysis(['meat' => null, 'meats' => [], 'meat_mode' => 'traditional', 'beef_variant' => null, 'extra_beef' => 0]),
        );

        $this->assertTrue($scores['selections']);
    }

    public function test_traditional_mode_is_not_neutral_when_it_conflicts_with_beef_only(): void
    {
        $scorer = app(CopilotEvaluationScorer::class);

        $this->assertFalse($scorer->score($this->case(['meat_mode' => 'beef_only']), $this->analysis(['meats' => ['porco'], 'meat_mode' => 'traditional']))['selections']);
        $this->assertFalse($scorer->score($this->case(['meats' => ['porco']]), $this->analysis(['meat_mode' => 'beef_only']))['selections']);
    }

    public function test_it_treats_unordered_traditional_meats_as_the_same_selection(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case(['meats' => ['frango ao molho', 'porco']]),
            $this->analysis(['meats' => ['porco', 'frango_ao_molho']]),
        );

        $this->assertTrue($scores['selections']);
    }

    public function test_it_does_not_ignore_a_non_default_extra_beef_selection(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case(['extra_beef' => 1]),
            $this->analysis(['extra_beef' => 0]),
        );

        $this->assertFalse($scores['selections']);
    }

    public function test_it_treats_the_canonical_beef_variant_as_redundant_only_for_beef_only_mode(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case(['meat_mode' => 'beef_only']),
            $this->analysis(['meat_mode' => 'beef_only', 'beef_variant' => 'somente bife']),
        );

        $this->assertTrue($scores['selections']);
    }

    public function test_it_keeps_incompatible_beef_variants_strict(): void
    {
        $scorer = app(CopilotEvaluationScorer::class);

        $this->assertFalse($scorer->score($this->case(['meat_mode' => 'beef_only']), $this->analysis(['meat_mode' => 'beef_only', 'beef_variant' => 'especial']))['selections']);
        $this->assertFalse($scorer->score($this->case(['beef_variant' => 'especial']), $this->analysis(['meat_mode' => 'beef_only']))['selections']);
        $this->assertFalse($scorer->score($this->case(['extra_beef' => 1]), $this->analysis(['meat_mode' => 'beef_only', 'beef_variant' => 'bife']))['selections']);
    }

    public function test_it_normalizes_notes_only_for_scoring(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            $this->case([], 'Pouco feijão'),
            $this->analysis([], ' pouco feijao '),
        );

        $this->assertTrue($scores['notes']);
    }

    public function test_it_canonicalizes_equivalent_missing_information_codes_without_hiding_distinct_requirements(): void
    {
        $scorer = app(CopilotEvaluationScorer::class);

        $this->assertTrue($scorer->score($this->case([], '', ['CARNE']), $this->analysis([], '', ['MEAT', 'N5_CASA_CARNE']))['missing_information']);
        $this->assertTrue($scorer->score($this->case([], '', ['SALADA']), $this->analysis([], '', ['SALADA_REQUIRED', 'SALADA']))['missing_information']);
        $this->assertTrue($scorer->score($this->case([], '', ['ADDRESS', 'CARNE']), $this->analysis([], '', ['ADDRESS', 'MEAT']))['missing_information']);
        $this->assertTrue($scorer->score($this->case([], '', ['ADDRESS', 'CARNE']), $this->analysis([], '', ['DELIVERY_ADDRESS', 'CARNE']))['missing_information']);
        $this->assertTrue($scorer->score($this->case([], '', ['PREVIOUS_ORDER_REFERENCE']), $this->analysis([], '', ['ITEM_TO_REPEAT']))['missing_information']);
        $this->assertTrue($scorer->score($this->case([], '', ['MENU_ITEM']), $this->analysis([], '', ['MENU_PRODUCT']))['missing_information']);
        $this->assertFalse($scorer->score($this->case([], '', ['CARNE']), $this->analysis([], '', ['CARNE', 'EXTRA_BEEF_QUANTITY']))['missing_information']);
    }

    public function test_it_compares_model_removal_codes_by_their_canonical_identity(): void
    {
        $scores = app(CopilotEvaluationScorer::class)->score(
            [...$this->case([]), 'expected_removals' => ['Sem Salada', 'Sem Feijao', 'Sem Mandioca']],
            [...$this->analysis([]), 'draft_order' => ['items' => [[
                'menu_item_slug' => 'n8-tradicional',
                'quantity' => 1,
                'selections' => [],
                'item_notes' => '',
                'removed_components' => ['salada_casa', 'feijao', 'mandioca'],
            ]], 'fulfillment' => null]],
        );

        $this->assertTrue($scores['removals']);
    }

    public function test_it_ignores_only_simple_terminal_note_punctuation(): void
    {
        $scorer = app(CopilotEvaluationScorer::class);

        $this->assertTrue($scorer->score($this->case([], 'Pouco feijao'), $this->analysis([], 'Pouco feijao.'))['notes']);
        $this->assertFalse($scorer->score($this->case([], 'Pouco feijao'), $this->analysis([], 'Sem cebola, pouco feijao'))['notes']);
    }

    /** @param array<string,mixed> $selections @return array<string,mixed> */
    private function case(array $selections, string $notes = '', array $missing = []): array
    {
        return [
            'intent' => 'ORDER_CREATE',
            'product' => 'n8-tradicional',
            'expected_quantity' => 1,
            'expected_selections' => $selections,
            'expected_notes' => $notes,
            'expected_fulfillment' => null,
            'missing' => $missing,
        ];
    }

    /** @param array<string,mixed> $selections @return array<string,mixed> */
    private function analysis(array $selections, string $notes = '', array $missing = []): array
    {
        return [
            'intent' => 'ORDER_CREATE',
            'draft_order' => ['items' => [[
                'menu_item_slug' => 'n8-tradicional',
                'quantity' => 1,
                'selections' => $selections,
                'item_notes' => $notes,
            ]], 'fulfillment' => null],
            'missing_information' => array_map(fn (string $code): array => ['code' => $code, 'label' => $code], $missing),
            'requires_human_review' => true,
        ];
    }
}
