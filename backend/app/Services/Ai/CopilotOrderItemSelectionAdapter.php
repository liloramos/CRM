<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductOptionGroup;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Menu\MenuComponentPresentation;
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
        private readonly CopilotCanonicalEntityResolver $entities,
        private readonly CopilotDailyMeatEntityResolver $dailyMeatEntities,
        private readonly MenuComponentPresentation $componentPresentation,
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
        $freeAssemblyWithoutSalad = $this->isTraditionalMarmita($product)
            && ($selections['salada'] ?? null) === 'none';
        if ($freeAssemblyWithoutSalad && ! $this->removals->isGrounded('salada', $customerMessages)) {
            $warnings[] = [
                'code' => 'UNGROUNDED_SELECTION',
                'message' => 'A escolha sem salada nao possui evidencia no pedido do cliente.',
            ];
            $selections['salada'] = null;
            $freeAssemblyWithoutSalad = false;
        }

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
            if ($freeAssemblyWithoutSalad && $this->key((string) $removed) === 'salada') {
                continue;
            }
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
                    'salada' => $freeAssemblyWithoutSalad ? 'none' : null,
                ],
                $quantity,
                [
                    'removed_component_ids' => array_values(array_unique($removedComponentIds)),
                    'removed_group_codes' => array_values(array_unique($removedGroupCodes)),
                    'daily_component_ids' => array_values(array_unique(array_map('intval', $item['daily_component_ids'] ?? []))),
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
                'canonical_selection_quote' => $validated['selection_quote'] ?? null,
                // This is server-produced output from OrderItemSelectionValidator. It is
                // consumed only by the ACT_SAFE order stager, never by provider output.
                'validated_order_options' => $validated['options'] ?? [],
            ],
            'warnings' => $this->dedupeWarnings($warnings),
        ];
    }

    /** @param array<string,mixed> $item @param list<array<string,mixed>> $customerMessages @return array<string,mixed> */
    public function recoverExplicitDailyMeats(Company $company, Product $product, CarbonInterface $date, array $item, array $customerMessages): array
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return $item;
        }

        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
        if (in_array($selections['meat_mode'] ?? 'traditional', ['none', 'beef_only'], true)) {
            return $item;
        }

        $text = $this->customerText($customerMessages);
        if ($text === '') {
            return $item;
        }

        $meats = collect($selections['meats'] ?? $selections['meat'] ?? [])
            ->filter(fn (mixed $meat): bool => is_string($meat) && $meat !== '')
            ->map(function (string $meat) use ($company, $product, $date): string {
                $component = $this->resolveDailyMeat($company, $product, $date, $meat);

                return $component instanceof MenuComponent
                    ? (string) ($component->display_name ?: $component->name)
                    : $meat;
            })
            ->values()
            ->all();
        $dailyResolution = $this->dailyMeatEntities->resolve($company, $product, $date, $text);
        $candidates = collect($dailyResolution['components']);
        $resolution = collect($dailyResolution)->except('components')->all();
        foreach ($resolution['resolved'] as $match) {
            $component = $candidates->firstWhere('id', (int) $match['canonical_id']);
            if (! $component instanceof MenuComponent) {
                continue;
            }
            $name = (string) ($component->display_name ?: $component->name);
            if (! collect($meats)->contains(fn (string $meat): bool => $this->key($meat) === $this->key($name))) {
                $meats[] = $name;
            }
        }

        return [
            ...$item,
            'selections' => [...$selections, 'meat' => null, 'meats' => $meats],
            'canonical_entity_resolution' => [
                ...(array) ($item['canonical_entity_resolution'] ?? []),
                'meats' => $resolution,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $customerMessages @return list<string> */
    public function explicitDailyMeatNames(Company $company, Product $product, CarbonInterface $date, array $customerMessages): array
    {
        $text = $this->customerText($customerMessages);

        return $this->dailyMeatEntities->names($company, $product, $date, $text);
    }

    /**
     * Resolves only real, available daily buffet components. Text is never persisted as a
     * component identity: the operational validator receives canonical menu component IDs.
     *
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $customerMessages
     * @return array{item:array<string,mixed>,warnings:list<array{code:string,message:string}>}
     */
    public function recoverExplicitDailyComponents(Company $company, Product $product, CarbonInterface $date, array $item, array $customerMessages): array
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return ['item' => $item, 'warnings' => []];
        }

        $text = $this->customerText($customerMessages);
        if ($text === '') {
            return ['item' => $item, 'warnings' => []];
        }
        if ($this->removals->isGrounded('salada', $customerMessages)) {
            $text = (string) preg_replace('/\b(?:sem|nao\s+(?:quero|coloca|mande))\s+saladas?\b/ui', ' ', Str::ascii($text));
        }

        $day = $this->dailyMenu->day($company, $date);
        $available = collect(data_get($day, 'sections', []))
            ->except('meat')
            ->flatMap(fn (array $section): array => $section)
            ->filter(fn (array $entry): bool => (bool) ($entry['available'] ?? false))
            ->map(fn (array $entry): mixed => data_get($entry, 'component'))
            ->filter()
            ->values();
        $availableIds = $available->map(fn (mixed $component): int => (int) data_get($component, 'id'))->filter()->all();
        $unavailable = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('component_type', '!=', 'meat')
            ->whereNotIn('id', $availableIds)
            ->get()
            ->values();

        $componentIds = collect($item['daily_component_ids'] ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $all = $available->concat($unavailable)->unique(fn (mixed $component): int => (int) data_get($component, 'id'))->values();
        $availableIdsLookup = array_fill_keys(array_map('intval', $availableIds), true);
        $resolution = $this->entities->resolve($text, $this->entityRows($all, false, $availableIdsLookup));
        foreach ($resolution['resolved'] as $match) {
            $id = (int) $match['canonical_id'];
            if (($match['available_today'] ?? false) && ! in_array($id, $componentIds, true)) {
                $componentIds[] = $id;
            }
        }

        $warnings = [];
        if (collect($resolution['resolved'])->contains(fn (array $match): bool => ! ($match['available_today'] ?? false))) {
            $warnings[] = [
                'code' => 'UNAVAILABLE_DAILY_COMPONENT',
                'message' => 'Um componente citado pelo cliente não está disponível hoje e não foi incluído.',
            ];
        }

        $resolvedIds = collect($resolution['resolved'])->pluck('canonical_id')->map(fn (mixed $id): int => (int) $id);
        $confirmedIds = $resolvedIds
            ->merge(array_map('intval', $componentIds))
            ->unique()
            ->values();
        $existingCandidates = collect((array) ($item['daily_component_candidates'] ?? []))
            ->filter(fn (mixed $candidate): bool => is_array($candidate))
            ->reject(fn (array $candidate): bool => $confirmedIds->intersect(
                array_map('intval', (array) ($candidate['component_ids'] ?? [])),
            )->isNotEmpty());
        $newCandidates = collect($resolution['ambiguous'])->map(fn (array $candidate): array => [
            'token' => (string) ($candidate['matched_text'] ?? ''),
            'component_ids' => array_values(array_filter(array_map('intval', (array) ($candidate['candidate_ids'] ?? [])), fn (int $id): bool => isset($availableIdsLookup[$id]))),
            'names' => array_values((array) ($candidate['candidate_names'] ?? [])),
            'selection_source' => 'customer_explicit',
            'status' => 'candidate',
            'span' => [
                'char_start' => (int) ($candidate['char_start'] ?? 0),
                'char_end' => (int) ($candidate['char_end'] ?? 0),
                'token_start' => (int) ($candidate['token_start'] ?? 0),
                'token_end' => (int) ($candidate['token_end'] ?? 0),
            ],
        ])->filter(fn (array $candidate): bool => count($candidate['component_ids']) > 1);
        $candidates = $existingCandidates
            ->merge($newCandidates)
            ->unique(fn (array $candidate): string => implode(',', array_map('intval', (array) ($candidate['component_ids'] ?? []))))
            ->values()
            ->all();

        return [
            'item' => [
                ...$item,
                'daily_component_ids' => array_values(array_unique($componentIds)),
                'daily_component_candidates' => $candidates,
                'canonical_entity_resolution' => [
                    ...(array) ($item['canonical_entity_resolution'] ?? []),
                    'components' => $resolution,
                ],
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * N8/N9 Livre have no default salad to remove. "Sem salada" is instead an
     * explicit free-assembly choice, kept apart from fixed-component removals.
     *
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $customerMessages
     * @return array<string,mixed>
     */
    public function recoverExplicitFreeAssemblySaladOptOut(Company $company, Product $product, CarbonInterface $date, array $item, array $customerMessages): array
    {
        if (! $this->isTraditionalMarmita($product) || ! $this->removals->isGrounded('salada', $customerMessages)) {
            return $item;
        }

        $saladComponentIds = collect(data_get($this->dailyMenu->day($company, $date), 'sections.salad', []))
            ->map(fn (array $entry): int => (int) data_get($entry, 'component.id'))
            ->filter(fn (int $id): bool => $id > 0)
            ->all();
        $dailyComponentIds = collect($item['daily_component_ids'] ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => in_array($id, $saladComponentIds, true))
            ->values()
            ->all();
        $removedComponents = collect($item['removed_components'] ?? [])
            ->reject(fn (mixed $removed): bool => is_string($removed) && $this->key($removed) === 'salada')
            ->values()
            ->all();
        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];

        return [
            ...$item,
            'daily_component_ids' => $dailyComponentIds,
            'removed_components' => $removedComponents,
            'selections' => [...$selections, 'salada' => 'none'],
        ];
    }

    /** @return list<array{0:string,1:string}> */
    private function selectionValues(Product $product, array $selections): array
    {
        $values = [];
        foreach ($selections as $key => $selection) {
            $isTraditionalMarmita = in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true);
            if (in_array((string) $key, ['meat_mode', 'beef_variant', 'extra_beef'], true)
                || ($isTraditionalMarmita && in_array((string) $key, ['meat', 'meats', 'salada'], true))) {
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
        $components = collect();
        $unresolved = [];
        foreach ($meats as $meat) {
            if (! is_string($meat)) {
                continue;
            }
            if ($this->isTraditionalMarmita($product)) {
                $resolution = $this->dailyMeatEntities->resolve($company, $product, $date, $meat);
                if ($resolution['ambiguous'] !== []) {
                    $warnings[] = ['code' => 'AMBIGUOUS_MEAT', 'message' => 'A carne informada corresponde a mais de uma opcao disponivel.'];

                    continue;
                }
                $component = collect($resolution['components'])->firstWhere(
                    'id',
                    (int) data_get($resolution, 'resolved.0.canonical_id'),
                );
            } else {
                $component = $this->resolveLink($product, 'carne', $meat)?->component;
            }
            if (! $component) {
                $unresolved[] = $meat;

                continue;
            }
            $components->put((int) $component->id, $component);
        }

        foreach ($unresolved as $meat) {
            if ($this->selectionIsCoveredByCanonicalMeats($meat, $components->values()->all())) {
                continue;
            }
            $warnings[] = ['code' => 'UNRESOLVED_MEAT', 'message' => 'Uma carne sugerida nao pertence de forma inequivoca ao produto.'];
        }

        return $components->values()->all();
    }

    /** @param list<MenuComponent> $components */
    private function selectionIsCoveredByCanonicalMeats(string $selection, array $components): bool
    {
        $residual = $this->key($selection);
        if ($residual === '' || $components === []) {
            return false;
        }

        $identities = collect($components)
            ->flatMap(fn (MenuComponent $component): array => [
                $this->key((string) $component->slug),
                $this->key((string) $component->name),
                $this->key((string) $component->display_name),
            ])
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $identity): int => strlen($identity));
        foreach ($identities as $identity) {
            $residual = str_replace($identity, '', $residual);
        }

        return in_array($residual, ['', 'e', 'com'], true);
    }

    private function resolveDailyMeat(Company $company, Product $product, CarbonInterface $date, string $value): ?MenuComponent
    {
        $resolution = $this->dailyMeatEntities->resolve($company, $product, $date, $value);
        if ($resolution['ambiguous'] !== [] || count($resolution['resolved']) !== 1) {
            return null;
        }

        return collect($resolution['components'])->firstWhere(
            'id',
            (int) data_get($resolution, 'resolved.0.canonical_id'),
        );
    }

    /** @return list<MenuComponent> */
    private function dailyMeatCandidates(Company $company, Product $product, CarbonInterface $date): array
    {
        return $this->dailyMeatEntities->candidates($company, $product, $date);
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

    private function isTraditionalMarmita(Product $product): bool
    {
        return in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true);
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

    private function customerText(array $messages): string
    {
        return collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->implode(' ');
    }

    /**
     * @param  iterable<mixed>  $components
     * @param  array<int,bool>  $availableIds
     * @return list<array<string,mixed>>
     */
    private function entityRows(iterable $components, bool $meat, array $availableIds = []): array
    {
        $components = collect($components)->values();
        $tokens = $components->mapWithKeys(function (mixed $component): array {
            $identityTokens = Str::of((string) (data_get($component, 'display_name') ?: data_get($component, 'name')))
                ->ascii()
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', ' ')
                ->squish()
                ->explode(' ')
                ->filter(fn (string $token): bool => strlen($token) >= 4 && $token !== 'para')
                ->unique()
                ->values()
                ->all();

            return [(int) data_get($component, 'id') => $identityTokens];
        });
        $tokenCounts = $components
            ->filter(fn (mixed $component): bool => $availableIds === [] || isset($availableIds[(int) data_get($component, 'id')]))
            ->flatMap(fn (mixed $component): array => $tokens->get((int) data_get($component, 'id'), []))
            ->countBy();

        return $components->map(function (mixed $component) use ($tokens, $tokenCounts, $meat, $availableIds): array {
            $id = (int) data_get($component, 'id');
            $identityTokens = $tokens->get($id, []);
            $leading = (string) ($identityTokens[0] ?? '');
            $aliases = [
                ...collect($identityTokens)
                    ->filter(fn (string $token): bool => $token === $leading || (int) $tokenCounts->get($token, 0) === 1)
                    ->map(fn (string $token): array => ['value' => $token, 'source' => 'inferred_alias'])
                    ->all(),
                ...($component instanceof MenuComponent ? $this->componentPresentation->searchAliases($component) : []),
            ];
            if (! $meat && collect([
                data_get($component, 'slug'), data_get($component, 'name'), data_get($component, 'display_name'),
            ])->contains(fn (mixed $value): bool => $this->key((string) $value) === 'arrozbranco')) {
                $aliases[] = 'arroz';
            }

            return [
                'id' => $id,
                'slug' => (string) data_get($component, 'slug'),
                'name' => (string) (data_get($component, 'display_name') ?: data_get($component, 'name')),
                'display_name' => (string) data_get($component, 'display_name'),
                'type' => $meat ? 'meat' : (string) data_get($component, 'component_type.value', data_get($component, 'component_type', 'component')),
                'available_today' => $availableIds === [] || isset($availableIds[$id]),
                'aliases' => collect($aliases)
                    ->unique(fn (mixed $alias): string => is_array($alias)
                        ? (string) ($alias['source'] ?? '').'|'.(string) ($alias['value'] ?? '')
                        : 'catalog_alias|'.(string) $alias)
                    ->values()
                    ->all(),
            ];
        })->all();
    }
}
