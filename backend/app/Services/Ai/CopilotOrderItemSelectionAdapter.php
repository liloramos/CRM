<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductOptionGroup;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Orders\OrderItemSelectionValidator;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CopilotOrderItemSelectionAdapter
{
    public function __construct(
        private readonly OrderItemSelectionValidator $validator,
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly CopilotSelectionGroundingGuard $grounding,
        private readonly CopilotMeatModeGroundingGuard $meatModes,
        private readonly CopilotRemovalGroundingGuard $removals,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     * @return array{item: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function validate(Company $company, Product $product, CarbonInterface $date, array $item, array $customerMessages = []): array
    {
        $product->loadMissing('optionGroups.componentOptions.component');
        $warnings = [];
        $rows = [];
        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
        $groundedMode = $this->meatModes->ground($product, $selections, $customerMessages);
        $selections = $groundedMode['selections'];
        $warnings = [...$warnings, ...$groundedMode['warnings']];

        foreach ($this->selectionValues($product, $selections) as [$groupHint, $value]) {
            $link = $this->resolveLink($product, $groupHint, $value);
            if (! $link) {
                $warnings[] = [
                    'code' => 'UNRESOLVED_SELECTION',
                    'message' => 'Uma escolha sugerida nao corresponde de forma inequivoca ao produto.',
                ];

                continue;
            }

            $rows[] = ['component_link_id' => $link->id, 'quantity' => $this->selectionQuantity($product, $link)];
        }

        $removedComponentIds = [];
        $removedGroupCodes = [];
        foreach ($item['removed_components'] ?? [] as $removed) {
            if (! $this->removals->isGrounded((string) $removed, $customerMessages)) {
                $warnings[] = [
                    'code' => 'UNGROUNDED_REMOVAL',
                    'message' => 'Uma remocao sugerida nao possui evidencia no pedido do cliente.',
                ];

                continue;
            }
            $link = $this->resolveLink($product, 'fixed', (string) $removed);
            if ($link) {
                if (data_get($product->composition_rules, 'fixed_components_removable', true) === false) {
                    $warnings[] = [
                        'code' => 'INVALID_REMOVAL',
                        'message' => 'A composicao base deste produto nao permite remocoes.',
                    ];

                    continue;
                }
                $removedComponentIds[] = (int) $link->menu_component_id;

                continue;
            }

            $group = $this->resolveRemovableGroup($product, (string) $removed);
            if (! $group) {
                $warnings[] = [
                    'code' => 'INVALID_REMOVAL',
                    'message' => 'Uma remocao sugerida nao pertence a composicao do produto.',
                ];

                continue;
            }

            if ($this->groupHasSelection($group, $rows)) {
                $warnings[] = [
                    'code' => 'INVALID_REMOVAL',
                    'message' => 'Uma remocao sugerida nao pode coexistir com uma escolha do mesmo grupo.',
                ];

                continue;
            }

            $removedGroupCodes[] = $group->code;
        }
        $safeRemovedComponents = $this->safeRemovedComponents($product, $removedComponentIds, $removedGroupCodes);

        $meatMode = $this->meatMode($selections);
        $resolvedMeats = $this->resolvedMeats($company, $product, $date, $selections, $warnings);
        if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            $grounded = $this->grounding->groundComponents(
                $resolvedMeats,
                $this->dailyMeatCandidates($company, $product, $date),
                $customerMessages,
                'MEAT',
            );
            $resolvedMeats = $grounded['components'];
            $warnings = [...$warnings, ...$grounded['warnings']];
        }
        $meatIds = array_map(fn (MenuComponent $component): int => (int) $component->id, $resolvedMeats);
        $extraBeef = $this->extraBeefQuantity($product, $selections, $warnings);
        $selections['extra_beef'] = $extraBeef;
        $safeSelections = $this->safeMeatSelections($product, $selections, $meatMode, $resolvedMeats);
        if ($resolvedMeats === [] && collect($warnings)->contains(fn (array $warning): bool => in_array($warning['code'] ?? null, ['AMBIGUOUS_MEAT', 'UNRESOLVED_MEAT'], true))) {
            $safeSelections['meat_selection_pending'] = true;
        }
        $quantity = (int) ($item['quantity'] ?? 1);

        if (in_array($meatMode, ['beef_only', 'none'], true) && $this->hasTraditionalMeatSelection($selections)) {
            $warnings[] = [
                'code' => 'DOMAIN_SELECTION_REJECTED',
                'message' => $meatMode === 'none' ? 'Sem carne nao pode ser combinado com carnes tradicionais.' : 'Somente bife nao pode ser combinado com carnes tradicionais.',
            ];

            return ['item' => [...$item, 'selections' => $safeSelections, 'removed_components' => $safeRemovedComponents, 'valid' => false], 'warnings' => $this->dedupeWarnings($warnings)];
        }

        try {
            $validated = $this->validator->validateStructuredSelections(
                $company->loadMissing('setting'),
                $product,
                $date,
                array_values(array_unique($rows, SORT_REGULAR)),
                [
                    'meat_mode' => $meatMode,
                    'traditional_meat_component_ids' => $meatIds,
                    'extra_beef_quantity' => $extraBeef,
                ],
                $quantity,
                [
                    'removed_component_ids' => array_values(array_unique($removedComponentIds)),
                    'removed_group_codes' => array_values(array_unique($removedGroupCodes)),
                ],
            );
        } catch (DomainException|ValidationException $exception) {
            $warnings[] = [
                'code' => 'DOMAIN_SELECTION_REJECTED',
                'message' => $this->validationMessage($exception),
            ];

            return ['item' => [...$item, 'selections' => $safeSelections, 'removed_components' => $safeRemovedComponents, 'valid' => false], 'warnings' => $this->dedupeWarnings($warnings)];
        }

        return [
            'item' => [
                ...$item,
                'selections' => $safeSelections,
                'valid' => $warnings === [],
                'removed_components' => $validated['removed_ingredients'] ?? [],
                'resolved_selections' => $validated['selected_components'] ?? [],
                'unit_price_cents' => $validated['unit_price_cents'] ?? $product->base_price_cents,
            ],
            'warnings' => $this->dedupeWarnings($warnings),
        ];
    }

    /** @return list<array{0:string,1:string}> */
    private function selectionValues(Product $product, array $selections): array
    {
        $values = [];
        foreach ($selections as $key => $selection) {
            $isTraditionalMarmita = in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true);
            if (in_array((string) $key, ['meat_mode', 'beef_variant', 'extra_beef'], true)
                || ($isTraditionalMarmita && in_array((string) $key, ['meat', 'meats'], true))) {
                continue;
            }
            foreach (is_array($selection) ? $selection : [$selection] as $value) {
                if (is_string($value) && filled($value)) {
                    $values[] = [(string) $key, $value];
                }
            }
        }

        return $values;
    }

    private function resolveLink(Product $product, string $groupHint, string $value): ?ProductGroupComponent
    {
        $needle = $this->key($value);
        if ($needle === '') {
            return null;
        }
        $groups = $product->optionGroups;
        if ($groupHint === 'fixed') {
            $groups = $groups->filter(fn ($group): bool => $group->selection_mode->value === 'fixed');
        } elseif ($groupHint !== '') {
            $hint = $this->key($groupHint);
            $matched = $groups->filter(fn ($group): bool => str_contains($this->key((string) $group->code), $hint) || str_contains($this->key((string) $group->label), $hint));
            if ($matched->isNotEmpty()) {
                $groups = $matched;
            }
        }

        $matches = $groups->flatMap(fn ($group) => $group->componentOptions)
            ->filter(fn (ProductGroupComponent $link): bool => $link->is_active && $link->component && in_array($needle, [
                $this->key((string) $link->component->slug),
                $this->key((string) $link->component->name),
                $this->key((string) $link->component->display_name),
            ], true))
            ->unique('id')
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function selectionQuantity(Product $product, ProductGroupComponent $link): int
    {
        $group = $product->optionGroups->firstWhere('id', $link->product_option_group_id);
        if (! $group) {
            return 1;
        }

        if ($group->min_quantity !== null && $group->max_quantity !== null && (int) $group->min_quantity === (int) $group->max_quantity) {
            return (int) $group->min_quantity;
        }

        return max(1, (int) ($link->included_quantity ?? 1));
    }

    private function resolveRemovableGroup(Product $product, string $value): ?ProductOptionGroup
    {
        $needle = $this->key($value);
        if ($needle === '') {
            return null;
        }

        $allowedCodes = collect(data_get($product->composition_rules, 'removable_group_codes', []))
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => trim($code))
            ->all();

        return $product->optionGroups
            ->filter(fn ($group): bool => in_array($group->code, $allowedCodes, true))
            ->first(function ($group) use ($needle): bool {
                $code = $this->key((string) $group->code);
                $label = $this->key((string) $group->label);

                return $needle === $code
                    || $needle === $label
                    || ($needle === 'salada' && (str_contains($code, 'salada') || str_contains($label, 'salada')));
            });
    }

    /** @param list<array{component_link_id:int,quantity:int}> $rows */
    private function groupHasSelection(ProductOptionGroup $group, array $rows): bool
    {
        $selectedIds = array_column($rows, 'component_link_id');

        return $group->componentOptions->contains(
            fn (ProductGroupComponent $link): bool => in_array((int) $link->id, $selectedIds, true),
        );
    }

    /** @param list<int> $componentIds @param list<string> $groupCodes @return list<string> */
    private function safeRemovedComponents(Product $product, array $componentIds, array $groupCodes): array
    {
        $fixed = $product->optionGroups
            ->filter(fn (ProductOptionGroup $group): bool => $group->selection_mode->value === 'fixed')
            ->flatMap(fn (ProductOptionGroup $group) => $group->componentOptions)
            ->filter(fn (ProductGroupComponent $link): bool => in_array((int) $link->menu_component_id, $componentIds, true))
            ->map(fn (ProductGroupComponent $link): string => 'Sem '.$this->componentName($link));
        $groups = $product->optionGroups
            ->whereIn('code', $groupCodes)
            ->map(fn (ProductOptionGroup $group): string => 'Sem '.$this->removableGroupLabel($group));

        return $fixed->merge($groups)->unique()->values()->all();
    }

    private function componentName(ProductGroupComponent $link): string
    {
        return (string) ($link->component?->display_name ?: $link->component?->name ?: 'Ingrediente');
    }

    private function removableGroupLabel(ProductOptionGroup $group): string
    {
        return match ($group->code) {
            'salada', 'salada_casa' => 'Salada',
            default => (string) $group->label,
        };
    }

    /** @param list<array<string,mixed>> $warnings @return list<MenuComponent> */
    private function resolvedMeats(Company $company, Product $product, CarbonInterface $date, array $selections, array &$warnings): array
    {
        $meats = $this->meatValues($product, $selections);
        $meats = is_array($meats) ? $meats : [$meats];
        $components = [];
        foreach ($meats as $meat) {
            if (! is_string($meat)) {
                continue;
            }
            $component = $this->resolveDailyMeat($company, $product, $date, $meat);
            if (! $component) {
                $warnings[] = ['code' => 'UNRESOLVED_MEAT', 'message' => 'Uma carne sugerida nao pertence de forma inequivoca ao produto.'];

                continue;
            }
            $components[] = $component;
        }

        return $components;
    }

    private function resolveDailyMeat(Company $company, Product $product, CarbonInterface $date, string $value): ?MenuComponent
    {
        $link = $this->resolveLink($product, 'carne', $value);
        if ($link?->component) {
            return $link->component;
        }

        $needle = $this->key($value);
        $availableMeats = collect($this->dailyMeatCandidates($company, $product, $date));
        $matches = $availableMeats
            ->filter(fn (MenuComponent $component): bool => in_array($needle, [
                $this->key((string) $component->slug),
                $this->key((string) $component->name),
                $this->key((string) $component->display_name),
            ], true))
            ->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($needle !== 'frango') {
            return null;
        }

        $chickenMatches = $availableMeats
            ->filter(fn (MenuComponent $component): bool => str_contains($this->key((string) $component->slug), 'frango'))
            ->values();

        return $chickenMatches->count() === 1 ? $chickenMatches->first() : null;
    }

    /** @return list<MenuComponent> */
    private function dailyMeatCandidates(Company $company, Product $product, CarbonInterface $date): array
    {
        $availableMeatIds = collect(data_get($this->dailyMenu->day($company, $date), 'sections.meat', []))
            ->filter(fn (array $item): bool => (bool) ($item['available'] ?? false))
            ->map(fn (array $item): int => (int) data_get($item, 'component.id'))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->all();
        $availableMeats = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('component_type', 'meat')
            ->whereIn('id', $availableMeatIds)
            ->get()
            ->values();
        $productMeatIds = $product->optionGroups
            ->filter(fn ($group): bool => $this->key((string) $group->code) === 'carne')
            ->flatMap(fn ($group) => $group->componentOptions)
            ->filter(fn (ProductGroupComponent $link): bool => $link->is_active)
            ->pluck('menu_component_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($productMeatIds === [] && in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return $availableMeats->all();
        }

        return $availableMeats
            ->filter(fn (MenuComponent $component): bool => in_array((int) $component->id, $productMeatIds, true))
            ->values()
            ->all();
    }

    /** @param list<array<string,mixed>> $warnings */
    private function extraBeefQuantity(Product $product, array $selections, array &$warnings): int
    {
        $value = $selections['extra_beef'] ?? 0;
        if (! is_numeric($value) || (int) $value < 0 || (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true) && (int) $value > 1)) {
            $warnings[] = ['code' => 'INVALID_EXTRA_BEEF', 'message' => 'A quantidade de bife adicional sugerida nao e valida.'];

            return 0;
        }

        return (int) $value;
    }

    private function meatMode(array $selections): string
    {
        $value = $this->key((string) ($selections['meat_mode'] ?? $selections['beef_variant'] ?? 'traditional'));

        if (in_array($value, ['beefonly', 'somente bife', 'sobife'], true)) {
            return 'beef_only';
        }

        return in_array($value, ['none', 'semcarne'], true) ? 'none' : 'traditional';
    }

    private function hasTraditionalMeatSelection(array $selections): bool
    {
        $meats = $selections['meats'] ?? $selections['meat'] ?? [];

        return is_array($meats) ? $meats !== [] : filled($meats);
    }

    /** @return string|list<string>|null */
    private function meatValues(Product $product, array $selections): string|array|null
    {
        if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return filled($selections['meats'] ?? null) ? $selections['meats'] : ($selections['meat'] ?? null);
        }

        return $selections['meat'] ?? null;
    }

    /** @param list<MenuComponent> $resolvedMeats @return array<string,mixed> */
    private function safeMeatSelections(Product $product, array $selections, string $meatMode, array $resolvedMeats): array
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            $meat = $resolvedMeats[0] ?? null;
            if ($meatMode === 'none') {
                $selections['meat_mode'] = 'none';
            }
            $selections['meat'] = $meat?->display_name ?: $meat?->name;
            $selections['meats'] = [];

            return $selections;
        }

        if (in_array($meatMode, ['beef_only', 'none'], true)) {
            $selections['meat_mode'] = $meatMode;
        }
        $selections['meat'] = null;
        $selections['meats'] = in_array($meatMode, ['beef_only', 'none'], true)
            ? []
            : array_map(fn (MenuComponent $component): string => (string) ($component->display_name ?: $component->name), $resolvedMeats);

        return $selections;
    }

    /** @param list<array<string,mixed>> $warnings @return list<array<string,mixed>> */
    private function dedupeWarnings(array $warnings): array
    {
        return collect($warnings)
            ->unique(fn (array $warning): string => (string) ($warning['code'] ?? '').'|'.(string) ($warning['message'] ?? ''))
            ->values()
            ->all();
    }

    private function validationMessage(DomainException|ValidationException $exception): string
    {
        if ($exception instanceof ValidationException) {
            return (string) collect($exception->errors())->flatten()->first();
        }

        return $exception->getMessage();
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
