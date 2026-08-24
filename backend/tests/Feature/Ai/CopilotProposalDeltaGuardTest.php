<?php

namespace Tests\Feature\Ai;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Ai\CopilotProposalDeltaGuard;
use App\Services\Ai\CopilotSuggestedReplyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopilotProposalDeltaGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_only_the_current_n5_request_when_an_active_order_exists(): void
    {
        [$company, $n8, $n5] = $this->menu();

        $safe = $this->restrict($company, 'quero também uma N5 sem carne', [$n8, $n5]);

        $this->assertSame('n5-casa', $safe['draft_order']['items'][0]['menu_item_slug']);
        $this->assertCount(1, $safe['draft_order']['items']);
    }

    public function test_it_does_not_deduplicate_a_current_request_for_another_n8(): void
    {
        [$company, $n8, $n5] = $this->menu();

        $safe = $this->restrict($company, 'quero mais uma N8 de porco', [$n8, $n5]);

        $this->assertSame('n8-tradicional', $safe['draft_order']['items'][0]['menu_item_slug']);
        $this->assertCount(1, $safe['draft_order']['items']);
    }

    public function test_it_keeps_only_the_current_coca_request_when_an_active_order_exists(): void
    {
        [$company, $n8, $n5, $coca] = $this->menu();

        $safe = $this->restrict($company, 'e uma coca 600', [$n8, $n5, $coca]);

        $this->assertSame('coca-cola-600ml', $safe['draft_order']['items'][0]['menu_item_slug']);
        $this->assertCount(1, $safe['draft_order']['items']);
    }

    public function test_it_blocks_a_change_request_instead_of_turning_it_into_an_addition(): void
    {
        [$company, $n8] = $this->menu();

        $safe = $this->restrict($company, 'troca a carne da N8 para frango ao molho', [$n8]);

        $this->assertSame('ORDER_CHANGE', $safe['intent']);
        $this->assertSame([], $safe['draft_order']['items']);
        $this->assertContains('ORDER_CHANGE_REQUIRES_REVIEW', array_column($safe['warnings'], 'code'));
    }

    public function test_a_change_with_two_semantically_identical_items_does_not_require_a_target(): void
    {
        [$company, $n8] = $this->menu();
        $safe = $this->change($company, $n8, ['Frango ao molho'], [11, 12]);
        $safe = app(CopilotSuggestedReplyGuard::class)->restrict([...$safe, 'suggested_reply' => 'Voce tem duas N8 de Porco. Quer trocar a primeira ou a segunda para Frango ao molho?'], []);

        $this->assertSame('ORDER_CHANGE', $safe['intent']);
        $this->assertSame([], $safe['draft_order']['items']);
        $this->assertSame([], array_column($safe['missing_information'], 'code'));
        $this->assertSame(['Frango ao molho'], $safe['draft_order']['change_request']['to_selections']['meats']);
        $this->assertTrue($safe['draft_order']['change_request']['target_items_equivalent']);
        $this->assertStringNotContainsString('primeira', $safe['suggested_reply']);
        $this->assertStringContainsString('uma N8 Livre continua como esta', $safe['suggested_reply']);
    }

    public function test_a_change_with_semantically_distinct_items_requires_the_target(): void
    {
        [$company, $n8] = $this->menu();
        $safe = $this->change($company, $n8, ['Frango ao molho'], [
            ['id' => 11, 'product_id' => $n8->id, 'item_notes' => 'Sem cebola'],
            ['id' => 12, 'product_id' => $n8->id],
        ]);

        $this->assertSame(['TARGET_ORDER_ITEM'], array_column($safe['missing_information'], 'code'));
        $this->assertFalse($safe['draft_order']['change_request']['target_items_equivalent']);
    }

    public function test_a_change_with_one_matching_item_keeps_the_grounded_meat_for_human_review(): void
    {
        [$company, $n8] = $this->menu();
        $safe = $this->change($company, $n8, ['Frango ao molho'], [11]);
        $safe = app(CopilotSuggestedReplyGuard::class)->restrict([...$safe, 'suggested_reply' => 'Qual carne o cliente deseja?'], []);

        $this->assertSame([], $safe['missing_information']);
        $this->assertSame(['Frango ao molho'], $safe['draft_order']['change_request']['to_selections']['meats']);
        $this->assertSame('Vou confirmar essa alteracao para voce.', $safe['suggested_reply']);
    }

    public function test_a_change_without_a_new_meat_keeps_the_meat_question(): void
    {
        [$company, $n8] = $this->menu();
        $safe = $this->change($company, $n8, [], [11]);
        $safe = app(CopilotSuggestedReplyGuard::class)->restrict([...$safe, 'suggested_reply' => 'Qual carne o cliente deseja?'], []);

        $this->assertSame(['CARNE'], array_column($safe['missing_information'], 'code'));
        $this->assertSame('Qual carne você deseja?', $safe['suggested_reply']);
    }

    /** @param list<Product> $products @return array<string,mixed> */
    private function restrict(Company $company, string $latest, array $products): array
    {
        return app(CopilotProposalDeltaGuard::class)->restrict($company, [
            'intent' => 'ORDER_CREATE',
            'draft_order' => ['items' => array_map(fn (Product $product): array => [
                'menu_item_id' => $product->id,
                'menu_item_slug' => $product->slug,
                'quantity' => 1,
                'selections' => [],
            ], $products)],
            'warnings' => [],
        ], [
            'active_order' => ['code' => '20260821-0001'],
            'messages' => [['direction' => 'inbound', 'type' => 'text', 'body' => $latest]],
        ]);
    }

    /** @param list<string> $meats @param list<int|array<string,mixed>> $targetItems @return array<string,mixed> */
    private function change(Company $company, Product $product, array $meats, array $targetItems): array
    {
        return app(CopilotProposalDeltaGuard::class)->restrict($company, [
            'intent' => 'ORDER_CREATE',
            'draft_order' => ['items' => [[
                'menu_item_id' => $product->id,
                'menu_item_slug' => $product->slug,
                'product_name' => $product->name,
                'quantity' => 1,
                'selections' => ['meats' => $meats],
            ]]],
            'missing_information' => [['code' => 'CARNE', 'label' => 'Carnes']],
            'warnings' => [],
        ], [
            'active_order' => [
                'code' => '20260821-0001',
                'items' => array_map(fn (int|array $item): array => is_array($item) ? $item : ['id' => $item, 'product_id' => $product->id], $targetItems),
            ],
            'messages' => [['direction' => 'inbound', 'type' => 'text', 'body' => $meats === [] ? 'troca a carne da N8' : 'troca a carne da N8 para frango ao molho']],
        ]);
    }

    /** @return list<Product|Company> */
    private function menu(): array
    {
        $company = Company::query()->create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $category = ProductCategory::query()->create(['company_id' => $company->id, 'name' => 'Cardápio', 'slug' => 'cardapio']);
        $create = fn (string $name, string $slug): Product => Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'product_type' => 'marmita',
            'base_price_cents' => 1000,
            'currency' => 'BRL',
            'is_active' => true,
            'is_available_by_default' => true,
        ]);

        return [$company, $create('N8 Livre', 'n8-tradicional'), $create('N5 Casa', 'n5-casa'), $create('Coca-Cola 600 ml', 'coca-cola-600ml')];
    }
}
