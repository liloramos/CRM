<?php

namespace App\Services\Orders;

use App\Enums\ProductSelectionMode;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductGroupProduct;
use App\Models\ProductOptionGroup;
use App\Services\Menu\ComponentAvailabilityResolver;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;

class OrderItemSelectionValidator
{
    public function __construct(
        private readonly ComponentAvailabilityResolver $componentAvailability,
        private readonly StructuredProductConfigurationService $productConfiguration,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function validateStructuredSelections(
        Company $company,
        Product $product,
        CarbonInterface $date,
        array $rows,
    ): array {
        $product->loadMissing([
            'optionGroups.componentOptions.component',
            'optionGroups.productOptions.selectableProduct.category',
            'optionGroups.productOptions.selectableProduct.serviceDays',
        ]);

        if ($product->optionGroups->isEmpty()) {
            return [];
        }

        foreach ($rows as $row) {
            $hasComponent = ! empty($row['component_link_id']);
            $hasProduct = ! empty($row['product_link_id']);

            if ($hasComponent === $hasProduct) {
                throw new DomainException('Cada escolha estruturada deve informar um componente ou produto valido.');
            }
        }

        $componentRows = collect($rows)
            ->filter(fn (array $row): bool => ! empty($row['component_link_id']))
            ->keyBy(fn (array $row): int => (int) $row['component_link_id']);

        $productRows = collect($rows)
            ->filter(fn (array $row): bool => ! empty($row['product_link_id']))
            ->keyBy(fn (array $row): int => (int) $row['product_link_id']);

        $this->assertRowsBelongToProduct($product, $componentRows, $productRows);

        $validated = [];

        foreach ($product->optionGroups->sortBy([['display_order', 'asc'], ['id', 'asc']]) as $group) {
            $validated = [
                ...$validated,
                ...$this->validateGroup($company, $product, $date, $group, $componentRows, $productRows),
            ];
        }

        return $validated;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $componentRows
     * @param  Collection<int, array<string, mixed>>  $productRows
     */
    private function assertRowsBelongToProduct(Product $product, Collection $componentRows, Collection $productRows): void
    {
        $validComponentIds = $product->optionGroups
            ->flatMap(fn (ProductOptionGroup $group) => $group->componentOptions->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $validProductIds = $product->optionGroups
            ->flatMap(fn (ProductOptionGroup $group) => $group->productOptions->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $invalidComponentIds = $componentRows->keys()
            ->reject(fn (int $id): bool => in_array($id, $validComponentIds, true))
            ->values()
            ->all();

        $invalidProductIds = $productRows->keys()
            ->reject(fn (int $id): bool => in_array($id, $validProductIds, true))
            ->values()
            ->all();

        if ($invalidComponentIds !== [] || $invalidProductIds !== []) {
            throw new DomainException('Uma ou mais escolhas nao pertencem ao produto selecionado.');
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $componentRows
     * @param  Collection<int, array<string, mixed>>  $productRows
     * @return list<array<string, mixed>>
     */
    private function validateGroup(
        Company $company,
        Product $product,
        CarbonInterface $date,
        ProductOptionGroup $group,
        Collection $componentRows,
        Collection $productRows,
    ): array {
        if ($group->selection_mode === ProductSelectionMode::Fixed) {
            return $group->componentOptions
                ->sortBy([['display_order', 'asc'], ['id', 'asc']])
                ->filter(fn (ProductGroupComponent $link): bool => (bool) $link->is_active)
                ->map(fn (ProductGroupComponent $link): array => $this->componentSelectionRow($company, $product, $date, $group, $link, null))
                ->values()
                ->all();
        }

        $selectedComponents = $group->componentOptions
            ->filter(fn (ProductGroupComponent $link): bool => $componentRows->has((int) $link->id))
            ->values();

        $selectedProducts = $group->productOptions
            ->filter(fn (ProductGroupProduct $link): bool => $productRows->has((int) $link->id))
            ->values();

        $choiceCount = $selectedComponents->count() + $selectedProducts->count();
        $minChoices = (int) ($group->min_choices ?? ($group->is_required ? 1 : 0));
        $maxChoices = $group->max_choices !== null ? (int) $group->max_choices : null;

        if ($choiceCount < $minChoices) {
            throw new DomainException("Escolha obrigatoria ausente em {$group->label}.");
        }

        if ($maxChoices !== null && $choiceCount > $maxChoices) {
            throw new DomainException("Escolhas acima do limite em {$group->label}.");
        }

        if ($group->same_component_only && $choiceCount > 1) {
            throw new DomainException("{$group->label} nao permite mistura de opcoes.");
        }

        $validatedRows = [];
        $quantityTotal = 0;

        foreach ($selectedComponents as $link) {
            $sourceRow = $componentRows->get((int) $link->id);
            $row = $this->componentSelectionRow($company, $product, $date, $group, $link, $sourceRow);
            $quantityTotal += (int) $row['quantity'];
            $validatedRows[] = $row;
        }

        foreach ($selectedProducts as $link) {
            $sourceRow = $productRows->get((int) $link->id);
            $row = $this->productSelectionRow($company, $date, $group, $link, $sourceRow);
            $quantityTotal += (int) $row['quantity'];
            $validatedRows[] = $row;
        }

        $this->assertQuantityLimits($group, $choiceCount, $quantityTotal);

        return $validatedRows;
    }

    /**
     * @param  array<string, mixed>|null  $sourceRow
     * @return array<string, mixed>
     */
    private function componentSelectionRow(
        Company $company,
        Product $product,
        CarbonInterface $date,
        ProductOptionGroup $group,
        ProductGroupComponent $link,
        ?array $sourceRow,
    ): array {
        if (! $link->is_active || $link->requires_confirmation) {
            throw new DomainException("{$link->component->name} nao esta disponivel para este produto.");
        }

        $availability = $this->componentAvailability
            ->resolve($company, $link->component, $date, $product)
            ->toArray();

        if (! $availability['available']) {
            throw new DomainException("{$link->component->name} esta indisponivel hoje.");
        }

        return [
            'product_option_id' => null,
            'name' => $link->component->name,
            'option_type' => $group->selection_mode->value,
            'group_code' => $group->code,
            'quantity' => $this->selectionQuantity($group, $link->included_quantity, $sourceRow),
            'price_delta_cents' => (int) $link->price_delta_cents,
            'metadata' => [
                'source' => 'product_group_component',
                'product_option_group_id' => $group->id,
                'product_group_component_id' => $link->id,
                'menu_component_id' => $link->menu_component_id,
                'selection_actor' => $group->selection_actor->value,
                'selection_mode' => $group->selection_mode->value,
                'final_price_cents' => $link->final_price_cents,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sourceRow
     * @return array<string, mixed>
     */
    private function productSelectionRow(
        Company $company,
        CarbonInterface $date,
        ProductOptionGroup $group,
        ProductGroupProduct $link,
        ?array $sourceRow,
    ): array {
        if (! $link->is_active || $link->requires_confirmation) {
            throw new DomainException("{$link->selectableProduct->name} nao esta disponivel para este produto.");
        }

        $availability = $this->productConfiguration->productAvailability($link->selectableProduct, $company, $date);

        if (! $availability['available']) {
            throw new DomainException("{$link->selectableProduct->name} esta indisponivel para venda nesta data.");
        }

        return [
            'product_option_id' => null,
            'name' => $link->selectableProduct->name,
            'option_type' => $group->selection_mode->value,
            'group_code' => $group->code,
            'quantity' => $this->selectionQuantity($group, $link->included_quantity, $sourceRow),
            'price_delta_cents' => (int) $link->price_delta_cents,
            'metadata' => [
                'source' => 'product_group_product',
                'product_option_group_id' => $group->id,
                'product_group_product_id' => $link->id,
                'selectable_product_id' => $link->selectable_product_id,
                'selection_actor' => $group->selection_actor->value,
                'selection_mode' => $group->selection_mode->value,
                'final_price_cents' => $link->final_price_cents,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sourceRow
     */
    private function selectionQuantity(ProductOptionGroup $group, ?int $includedQuantity, ?array $sourceRow): int
    {
        if (($sourceRow['quantity'] ?? null) !== null) {
            return max(1, (int) $sourceRow['quantity']);
        }

        if ($group->min_quantity !== null && $group->max_quantity !== null && (int) $group->min_quantity === (int) $group->max_quantity) {
            return (int) $group->min_quantity;
        }

        return max(1, (int) ($includedQuantity ?? 1));
    }

    private function assertQuantityLimits(ProductOptionGroup $group, int $choiceCount, int $quantityTotal): void
    {
        if ($choiceCount === 0) {
            return;
        }

        if ($group->min_quantity !== null && $quantityTotal < (int) $group->min_quantity) {
            throw new DomainException("Quantidade abaixo do minimo em {$group->label}.");
        }

        if ($group->max_quantity !== null && $quantityTotal > (int) $group->max_quantity) {
            throw new DomainException("Quantidade acima do limite em {$group->label}.");
        }
    }
}
