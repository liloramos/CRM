<?php

namespace App\Services\Orders;

use App\Enums\ProductSelectionMode;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductGroupProduct;
use App\Models\ProductOptionGroup;
use App\Services\Menu\ComponentAvailabilityResolver;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Menu\StructuredProductConfigurationService;
use App\Services\Menu\TraditionalMarmitaBeefRuleService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrderItemSelectionValidator
{
    public function __construct(
        private readonly ComponentAvailabilityResolver $componentAvailability,
        private readonly StructuredProductConfigurationService $productConfiguration,
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly TraditionalMarmitaBeefRuleService $beefRules,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meatSelection
     * @return array{options: list<array<string, mixed>>, unit_price_cents?: int, selected_components?: list<string>, removed_ingredients?: list<string>}
     */
    public function validateStructuredSelections(
        Company $company,
        Product $product,
        CarbonInterface $date,
        array $rows,
        array $meatSelection = [],
        int $itemQuantity = 1,
        array $compositionSelection = [],
    ): array {
        $product->loadMissing([
            'optionGroups.componentOptions.component',
            'optionGroups.productOptions.selectableProduct.category',
            'optionGroups.productOptions.selectableProduct.serviceDays',
        ]);

        if ($product->optionGroups->isEmpty()) {
            return ['options' => []];
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
        $this->assertDefaultCompositionBelongsToProduct($product, $compositionSelection);

        if ($this->hasTraditionalBeefRules($product)) {
            return $this->validateTraditionalMarmita(
                company: $company,
                product: $product,
                date: $date,
                componentRows: $componentRows,
                productRows: $productRows,
                meatSelection: $meatSelection,
                itemQuantity: $itemQuantity,
                compositionSelection: $compositionSelection,
            );
        }

        $validated = [];
        $selectedComponents = [];
        $removedIngredients = [];

        foreach ($product->optionGroups->sortBy([['display_order', 'asc'], ['id', 'asc']]) as $group) {
            $validated = [
                ...$validated,
                ...$this->validateGroup(
                    $company,
                    $product,
                    $date,
                    $group,
                    $componentRows,
                    $productRows,
                    $compositionSelection,
                    $selectedComponents,
                    $removedIngredients,
                ),
            ];
        }

        return [
            'options' => $validated,
            'selected_components' => $selectedComponents,
            'removed_ingredients' => $removedIngredients,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $componentRows
     * @param  Collection<int, array<string, mixed>>  $productRows
     * @param  array<string, mixed>  $meatSelection
     * @param  array<string, mixed>  $compositionSelection
     * @return array{options: list<array<string, mixed>>, unit_price_cents: int, selected_components: list<string>, removed_ingredients: list<string>}
     */
    private function validateTraditionalMarmita(
        Company $company,
        Product $product,
        CarbonInterface $date,
        Collection $componentRows,
        Collection $productRows,
        array $meatSelection,
        int $itemQuantity,
        array $compositionSelection,
    ): array {
        $this->rejectSpecialBeefRows($product, $componentRows);

        $mode = $meatSelection['meat_mode'] ?? 'traditional';
        $traditionalMeatIds = $this->integerList($meatSelection['traditional_meat_component_ids'] ?? []);
        $extraBeefQuantity = max(0, (int) ($meatSelection['extra_beef_quantity'] ?? 0));

        $quote = $this->quoteBeefRules($product, [
            'meat_mode' => $mode,
            'traditional_meat_component_ids' => $traditionalMeatIds,
            'extra_beef_quantity' => $extraBeefQuantity,
        ]);

        $validated = [];
        $selectedComponents = [];
        $removedIngredients = [];

        foreach ($product->optionGroups->sortBy([['display_order', 'asc'], ['id', 'asc']]) as $group) {
            if ($this->isBeefRuleGroup($group)) {
                continue;
            }

            if ($mode === 'beef_only' && $this->groupContainsTraditionalMeat($group)) {
                continue;
            }

            $validated = [
                ...$validated,
                ...$this->validateGroup(
                    $company,
                    $product,
                    $date,
                    $group,
                    $componentRows,
                    $productRows,
                    $compositionSelection,
                    $selectedComponents,
                    $removedIngredients,
                ),
            ];
        }

        if ($mode === 'beef_only') {
            $beefOnly = $this->configuredBeefLink($product, 'variacao_bife', 'beef_only');
            $this->assertComponentAvailable($company, $product, $date, $beefOnly, 'beef_only');

            return [
                'options' => [
                    ...$validated,
                    $this->beefOnlyRow($beefOnly, $quote),
                ],
                'unit_price_cents' => (int) $quote['total_cents'],
                'selected_components' => $selectedComponents,
                'removed_ingredients' => $removedIngredients,
            ];
        }

        $dailyMeats = $this->availableDailyMeats($company, $date);
        $traditionalRows = $this->traditionalMeatRows($dailyMeats, $traditionalMeatIds);

        if ($extraBeefQuantity > 0) {
            $extraBeef = $this->configuredBeefLink($product, 'bife_adicional', 'extra_beef_quantity');
            $this->assertComponentAvailable($company, $product, $date, $extraBeef, 'extra_beef_quantity');
            $traditionalRows[] = $this->extraBeefRow($extraBeef, $quote, $extraBeefQuantity, $itemQuantity);
        }

        return [
            'options' => [
                ...$validated,
                ...$traditionalRows,
            ],
            'unit_price_cents' => (int) $quote['total_cents'],
            'selected_components' => $selectedComponents,
            'removed_ingredients' => $removedIngredients,
        ];
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
     * @param  array<string, mixed>  $compositionSelection
     */
    private function assertDefaultCompositionBelongsToProduct(Product $product, array $compositionSelection): void
    {
        $defaultComponentIds = $product->optionGroups
            ->filter(fn (ProductOptionGroup $group): bool => $group->selection_mode === ProductSelectionMode::Fixed)
            ->flatMap(fn (ProductOptionGroup $group) => $group->componentOptions)
            ->filter(fn (ProductGroupComponent $link): bool => (bool) $link->is_active)
            ->map(fn (ProductGroupComponent $link): int => (int) $link->menu_component_id)
            ->unique()
            ->values()
            ->all();

        $includedComponentIds = $this->integerList($compositionSelection['included_component_ids'] ?? []);
        $removedComponentIds = $this->integerList($compositionSelection['removed_component_ids'] ?? []);
        $invalidIncluded = array_values(array_diff($includedComponentIds, $defaultComponentIds));
        $invalidRemoved = array_values(array_diff($removedComponentIds, $defaultComponentIds));
        $conflicting = array_values(array_intersect($includedComponentIds, $removedComponentIds));

        if ($invalidIncluded !== []) {
            throw ValidationException::withMessages([
                'included_component_ids' => ['Um dos ingredientes mantidos nao pertence aos itens incluidos por padrao deste produto.'],
            ]);
        }

        if ($invalidRemoved !== []) {
            throw ValidationException::withMessages([
                'removed_component_ids' => ['Somente ingredientes incluidos por padrao podem ser retirados.'],
            ]);
        }

        if ($conflicting !== []) {
            throw ValidationException::withMessages([
                'removed_component_ids' => ['O mesmo ingrediente nao pode ser mantido e retirado ao mesmo tempo.'],
            ]);
        }
    }

    private function hasTraditionalBeefRules(Product $product): bool
    {
        return in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true);
    }

    private function isBeefRuleGroup(ProductOptionGroup $group): bool
    {
        return in_array($group->code, ['variacao_bife', 'bife_adicional'], true);
    }

    private function groupContainsTraditionalMeat(ProductOptionGroup $group): bool
    {
        return $group->componentOptions
            ->contains(fn (ProductGroupComponent $link): bool => $link->component?->component_type?->value === 'meat');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $componentRows
     */
    private function rejectSpecialBeefRows(Product $product, Collection $componentRows): void
    {
        $specialLinkIds = $product->optionGroups
            ->filter(fn (ProductOptionGroup $group): bool => $this->isBeefRuleGroup($group))
            ->flatMap(fn (ProductOptionGroup $group) => $group->componentOptions->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $selectedSpecialIds = $componentRows->keys()
            ->filter(fn (int $id): bool => in_array($id, $specialLinkIds, true))
            ->values()
            ->all();

        if ($selectedSpecialIds !== []) {
            throw ValidationException::withMessages([
                'structured_options' => ['Use a escolha da carne para informar somente bife ou bife adicional.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    private function quoteBeefRules(Product $product, array $selection): array
    {
        try {
            return $this->beefRules->quote($product, $selection);
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    private function configuredBeefLink(Product $product, string $groupCode, string $field): ProductGroupComponent
    {
        $link = $product->optionGroups
            ->firstWhere('code', $groupCode)
            ?->componentOptions
            ->first(fn (ProductGroupComponent $componentLink): bool => $componentLink->component?->slug === 'bife');

        if (! $link instanceof ProductGroupComponent || ! $link->is_active || $link->requires_confirmation) {
            throw ValidationException::withMessages([
                $field => ['Esta opcao nao esta disponivel para o produto selecionado.'],
            ]);
        }

        return $link;
    }

    private function assertComponentAvailable(
        Company $company,
        Product $product,
        CarbonInterface $date,
        ProductGroupComponent $link,
        string $field,
    ): void {
        $availability = $this->componentAvailability
            ->resolve($company, $link->component, $date, $product)
            ->toArray();

        if (! $availability['available']) {
            throw ValidationException::withMessages([
                $field => ['Esta opcao nao esta disponivel para o produto selecionado.'],
            ]);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function availableDailyMeats(Company $company, CarbonInterface $date): Collection
    {
        $day = $this->dailyMenu->day($company, $date);

        return collect($day['sections']['meat'] ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['available'] ?? false))
            ->keyBy(fn (array $item): int => (int) data_get($item, 'component.id'));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $dailyMeats
     * @param  array<int, int>  $traditionalMeatIds
     * @return list<array<string, mixed>>
     */
    private function traditionalMeatRows(Collection $dailyMeats, array $traditionalMeatIds): array
    {
        $rows = [];
        $counts = array_count_values($traditionalMeatIds);

        foreach ($counts as $componentId => $quantity) {
            $item = $dailyMeats->get((int) $componentId);

            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'traditional_meat_component_ids' => ['Uma das carnes escolhidas nao esta disponivel hoje.'],
                ]);
            }

            $component = $item['component'];
            $name = (string) ($component['display_name'] ?? $component['name'] ?? 'Carne do dia');

            $rows[] = [
                'product_option_id' => null,
                'name' => $name,
                'option_type' => ProductSelectionMode::IncludedChoice->value,
                'group_code' => 'carne',
                'quantity' => (int) $quantity,
                'price_delta_cents' => 0,
                'total_price_cents' => 0,
                'metadata' => [
                    'source' => 'daily_menu_component',
                    'menu_component_id' => (int) $component['id'],
                    'meat_mode' => 'traditional',
                    'included_in_unit_price' => true,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function beefOnlyRow(ProductGroupComponent $link, array $quote): array
    {
        return [
            'product_option_id' => null,
            'name' => 'Somente bife',
            'option_type' => ProductSelectionMode::Variation->value,
            'group_code' => 'variacao_bife',
            'quantity' => 1,
            'price_delta_cents' => (int) ($link->price_delta_cents ?? 0),
            'total_price_cents' => 0,
            'metadata' => [
                'source' => 'product_group_component',
                'product_option_group_id' => $link->product_option_group_id,
                'product_group_component_id' => $link->id,
                'menu_component_id' => $link->menu_component_id,
                'meat_mode' => 'beef_only',
                'final_price_cents' => $quote['beef_only_final_price_cents'] ?? $link->final_price_cents,
                'included_in_unit_price' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function extraBeefRow(ProductGroupComponent $link, array $quote, int $extraBeefQuantity, int $itemQuantity): array
    {
        return [
            'product_option_id' => null,
            'name' => 'Bife adicional',
            'option_type' => ProductSelectionMode::Addon->value,
            'group_code' => 'bife_adicional',
            'quantity' => $extraBeefQuantity,
            'price_delta_cents' => (int) ($quote['extra_beef_unit_price_cents'] ?? $link->price_delta_cents ?? 0),
            'total_price_cents' => 0,
            'metadata' => [
                'source' => 'product_group_component',
                'product_option_group_id' => $link->product_option_group_id,
                'product_group_component_id' => $link->id,
                'menu_component_id' => $link->menu_component_id,
                'meat_mode' => 'traditional',
                'addition_code' => 'extra_beef',
                'per_unit_quantity' => $extraBeefQuantity,
                'line_quantity' => $itemQuantity,
                'included_in_unit_price' => true,
                'unit_price_cents' => $quote['extra_beef_unit_price_cents'] ?? $link->price_delta_cents,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $componentRows
     * @param  Collection<int, array<string, mixed>>  $productRows
     * @param  array<string, mixed>  $compositionSelection
     * @param  list<string>  $selectedComponents
     * @param  list<string>  $removedIngredients
     * @return list<array<string, mixed>>
     */
    private function validateGroup(
        Company $company,
        Product $product,
        CarbonInterface $date,
        ProductOptionGroup $group,
        Collection $componentRows,
        Collection $productRows,
        array $compositionSelection,
        array &$selectedComponents,
        array &$removedIngredients,
    ): array {
        if ($group->selection_mode === ProductSelectionMode::Fixed) {
            $removedComponentIds = $this->integerList($compositionSelection['removed_component_ids'] ?? []);

            return $group->componentOptions
                ->sortBy([['display_order', 'asc'], ['id', 'asc']])
                ->filter(fn (ProductGroupComponent $link): bool => (bool) $link->is_active)
                ->flatMap(function (ProductGroupComponent $link) use ($company, $product, $date, $group, $removedComponentIds, &$selectedComponents, &$removedIngredients): array {
                    $componentName = $this->componentName($link);

                    if (in_array((int) $link->menu_component_id, $removedComponentIds, true)) {
                        $removedIngredients[] = 'Sem '.$componentName;

                        return [];
                    }

                    $selectedComponents[] = $componentName;

                    return [
                        $this->componentSelectionRow(
                            $company,
                            $product,
                            $date,
                            $group,
                            $link,
                            null,
                            'included_default',
                        ),
                    ];
                })
                ->values()
                ->all();
        }

        $selectedComponentLinks = $group->componentOptions
            ->filter(fn (ProductGroupComponent $link): bool => $componentRows->has((int) $link->id))
            ->values();

        $selectedProductLinks = $group->productOptions
            ->filter(fn (ProductGroupProduct $link): bool => $productRows->has((int) $link->id))
            ->values();

        $choiceCount = $selectedComponentLinks->count() + $selectedProductLinks->count();
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

        foreach ($selectedComponentLinks as $link) {
            $sourceRow = $componentRows->get((int) $link->id);
            $row = $this->componentSelectionRow($company, $product, $date, $group, $link, $sourceRow, 'selected_choice');
            $quantityTotal += (int) $row['quantity'];
            $validatedRows[] = $row;
        }

        foreach ($selectedProductLinks as $link) {
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
        ?string $compositionRole = null,
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
                'composition_role' => $compositionRole,
                'included_in_unit_price' => (bool) $group->included_in_base_price,
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

    private function componentName(ProductGroupComponent $link): string
    {
        return (string) ($link->component?->display_name ?: $link->component?->name ?: 'Ingrediente');
    }

    /**
     * @return array<int, int>
     */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_numeric($item))
            ->map(fn (mixed $item): int => (int) $item)
            ->values()
            ->all();
    }
}
