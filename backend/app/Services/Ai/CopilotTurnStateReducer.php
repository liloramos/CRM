<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Reduces one validated conversational turn into the durable pending-sale snapshot.
 * Commercial validation remains in the existing draft validator/domain services.
 */
final class CopilotTurnStateReducer
{
    /** @param array<string,mixed> $analysis @param array<string,mixed> $context @return array<string,mixed> */
    public function reduce(array $analysis, array $context): array
    {
        $previous = is_array(data_get($context, 'pending_order_state'))
            ? (array) data_get($context, 'pending_order_state')
            : [];
        $readOnly = data_get($analysis, 'metadata.semantic_read_only') === true
            || collect((array) data_get($analysis, 'metadata.semantic_interpretation.intents', []))
                ->intersect(['ask_product_information', 'compare_products', 'ask_pending_slot_options'])
                ->isNotEmpty()
            || (in_array((string) ($analysis['intent'] ?? ''), [
                'MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST',
                'PAYMENT_QUESTION', 'DELIVERY_QUESTION', 'ORDER_STATUS', 'GENERAL_QUESTION',
            ], true) && in_array((string) data_get($analysis, 'metadata.reply_source', ''), [
                'daily_menu', 'product_catalog', 'operating_hours', 'operating_hours_unconfigured',
                'customer_facing_policy', 'semantic_grounded_product_information',
                'semantic_grounded_comparison', 'semantic_grounded_options',
            ], true));
        $previousDraft = is_array($previous['draft_order'] ?? null) ? $previous['draft_order'] : [];
        $candidateDraft = is_array($analysis['draft_order'] ?? null) ? $analysis['draft_order'] : [];
        $draft = $this->draft($previousDraft, $candidateDraft, $readOnly);
        $items = array_values((array) ($draft['items'] ?? []));
        $candidateItems = $readOnly
            ? array_values((array) ($previous['candidate_items'] ?? []))
            : $this->candidateItems($analysis, $previous, $items);
        $resolvedCandidate = $items !== [] && is_array(data_get($analysis, 'metadata.candidate_item'))
            ? [...(array) data_get($analysis, 'metadata.candidate_item'), 'status' => 'resolved']
            : ($previous['resolved_candidate'] ?? null);
        $offeredProductIds = $this->ids(
            (array) data_get($analysis, 'metadata.offered_product_ids', $previous['offered_product_ids'] ?? []),
        );
        $hasContext = $items !== []
            || $candidateItems !== []
            || $offeredProductIds !== []
            || (bool) ($previous['has_context'] ?? false)
            || is_array(data_get($context, 'active_order'));
        $constraints = $readOnly
            ? array_values((array) ($previous['constraints'] ?? []))
            : array_values((array) data_get($analysis, 'metadata.constraints', []));
        if (! $readOnly && $constraints === []) {
            $constraintCodes = [
                'CONFLICTING_MEAT_REQUEST', 'MEAT_ALLOWANCE_EXCEEDED',
                'PRODUCT_INCOMPATIBLE_WITH_EXPLICIT_SELECTIONS', 'INVALID_QUANTITY',
            ];
            $constraints = collect((array) ($analysis['warnings'] ?? []))
                ->filter(fn (mixed $warning): bool => is_array($warning)
                    && in_array(strtoupper((string) ($warning['code'] ?? '')), $constraintCodes, true))
                ->values()
                ->all();
        }
        $missing = $readOnly
            ? $this->requiredPreviousMissing($previous)
            : $this->codes((array) ($analysis['missing_information'] ?? []));

        if (! $readOnly && data_get($analysis, 'metadata.provider_failure') === true) {
            $missing = array_values(array_unique([
                ...$missing,
                ...$this->requiredPreviousMissing($previous),
            ]));
        }

        if ($items !== []) {
            $missing = array_values(array_diff($missing, ['PRODUCT', 'MENU_ITEM', 'N8_VARIANT']));
        } elseif ($candidateItems !== []) {
            $missing = array_values(array_unique([
                ...array_diff($missing, ['PRODUCT', 'MENU_ITEM']),
                'N8_VARIANT',
            ]));
        }
        if (collect($items)->contains(fn (mixed $item): bool => is_array($item)
            && (array) ($item['daily_component_candidates'] ?? []) !== [])) {
            $missing[] = 'ACOMPANHAMENTO';
        }
        if (collect($items)->contains(fn (mixed $item): bool => is_array($item) && ($item['valid'] ?? true) === false)) {
            $missing = array_values(array_unique([...$missing, ...$this->missingForInvalidItems($items, $context)]));
            $warningCodes = $this->codes((array) ($analysis['warnings'] ?? []));
            if (array_intersect($warningCodes, ['UNRESOLVED_MEAT', 'AMBIGUOUS_MEAT']) !== []) {
                $missing[] = 'CARNE';
            }
            if ($missing === []) {
                $missing[] = 'ACOMPANHAMENTO';
            }
        }

        if ($hasContext && $items === [] && array_intersect($missing, ['PRODUCT', 'MENU_ITEM', 'N8_VARIANT']) === []) {
            $missing[] = 'PRODUCT';
        }

        $previousObjective = strtoupper((string) ($previous['next_objective'] ?? ''));
        $confirmation = (string) data_get($analysis, 'metadata.semantic_interpretation.confirmation', 'unknown');
        $semanticIntents = array_map('strval', (array) data_get($analysis, 'metadata.semantic_interpretation.intents', []));
        $confirmed = is_array(data_get($context, 'active_order'))
            || data_get($previous, 'item_confirmation.status') === 'confirmed';
        $moreItems = is_array(data_get($context, 'active_order'))
            ? 'declined'
            : (string) data_get($previous, 'additional_items.status', 'pending');
        $draftChanged = ! $readOnly && $items !== [] && $previousDraft !== [] && $draft !== $previousDraft;

        if ($draftChanged
            && ! is_array(data_get($context, 'active_order'))
            && ! in_array($previousObjective, ['ASK_FULFILLMENT', 'ASK_LOCATION', 'CALCULATE_DELIVERY', 'ASK_PAYMENT_METHOD'], true)) {
            $confirmed = false;
            $moreItems = 'pending';
        }
        if (array_intersect($missing, ['PRODUCT', 'MENU_ITEM', 'N8_VARIANT', 'CARNE', 'SALADA', 'ACOMPANHAMENTO', 'VALID_QUANTITY', 'SABOR']) !== []
            || $constraints !== []) {
            $confirmed = false;
            $moreItems = 'pending';
        }
        $itemsAreCanonicallyValid = $items !== []
            && ! collect($items)->contains(fn (mixed $item): bool => ! is_array($item) || ($item['valid'] ?? true) === false);
        $requiredItemMissing = array_intersect($missing, [
            'PRODUCT', 'MENU_ITEM', 'N8_VARIANT', 'CARNE', 'SALADA', 'ACOMPANHAMENTO', 'VALID_QUANTITY', 'SABOR',
        ]);
        if ($previousObjective === 'CONFIRM_ITEM'
            && $itemsAreCanonicallyValid
            && $requiredItemMissing === []
            && $constraints === []
            && ($confirmation === 'yes' || in_array('confirm', $semanticIntents, true))) {
            $confirmed = true;
            $moreItems = 'pending';
        }
        if ($previousObjective === 'ASK_MORE_ITEMS') {
            if ($confirmation === 'no' || in_array('deny', $semanticIntents, true)) {
                $moreItems = 'declined';
            } elseif ($confirmation === 'yes' || in_array('confirm', $semanticIntents, true)) {
                $moreItems = 'accepted';
            }
        }

        [$phase, $objective] = $this->position(
            $hasContext,
            $items,
            $missing,
            $constraints,
            $confirmed,
            $moreItems,
            $draft,
            $context,
        );
        $assistantGoal = $candidateItems !== [] && $items === []
            ? $this->candidateVariantGoal($candidateItems[0], $context)
            : $this->assistantGoal($objective, $draft, $context, $offeredProductIds);
        $lifecycleMissing = $this->lifecycleMissing($objective, $missing);
        $pendingSlot = $assistantGoal === null ? null : [
            'code' => $lifecycleMissing[0] ?? $objective,
            'slot' => $assistantGoal['slot'],
        ];
        $location = data_get($analysis, 'metadata.customer_location')
            ?? ($previous['location'] ?? data_get($previous, 'customer_location'));
        $references = array_values((array) data_get(
            $analysis,
            'metadata.conversation_references',
            $previous['conversation_references'] ?? [],
        ));
        $terminalLifecycles = ['cancelled', 'superseded', 'complete'];
        $previousLifecycle = (string) ($previous['lifecycle'] ?? '');
        $sessionId = ! in_array($previousLifecycle, $terminalLifecycles, true) && filled($previous['session_id'] ?? null)
            ? (string) $previous['session_id']
            : 'attempt:'.((int) data_get($context, 'trigger_message.id') ?: Str::uuid()->toString());
        $lifecycle = is_array(data_get($context, 'active_order'))
            ? ($phase === 'COMPLETE' ? 'complete' : 'materialized')
            : ($confirmed ? 'confirmed' : 'open');
        $presentedProductIds = $this->ids([
            ...(array) data_get($previous, 'presented_options.product_ids', []),
            ...$offeredProductIds,
            ...collect($candidateItems)->flatMap(fn (array $candidate): array => (array) ($candidate['variant_candidates'] ?? []))->all(),
        ]);

        return [
            'session_id' => $sessionId,
            'attempt_id' => $sessionId,
            'lifecycle' => $lifecycle,
            'has_context' => $hasContext,
            'phase' => $phase,
            'draft_order' => $hasContext ? $draft : null,
            'resolved_slots' => $this->resolvedSlots($draft, $confirmed, $moreItems),
            'required_missing_slots' => array_values(array_unique($missing)),
            'missing_slots' => $lifecycleMissing,
            // Kept for compatibility with the context builder and existing audit consumers.
            'missing_fields' => $lifecycleMissing,
            'pending_slot' => $pendingSlot,
            'next_objective' => $objective,
            'assistant_goal' => $assistantGoal,
            'conversation_references' => $references,
            'constraints' => $constraints,
            'candidate_items' => $candidateItems,
            'resolved_candidate' => is_array($resolvedCandidate) ? $resolvedCandidate : null,
            'selected_components' => $this->projectSelectedComponents($draft, $candidateItems, $context, $analysis),
            'offered_product_ids' => $offeredProductIds,
            'presented_options' => ['product_ids' => $presentedProductIds],
            'item_confirmation' => ['status' => $confirmed ? 'confirmed' : 'pending'],
            'additional_items' => ['status' => $moreItems],
            'fulfillment' => $draft['fulfillment'] ?? null,
            'address' => trim((string) ($draft['address'] ?? '')) ?: null,
            'location' => is_array($location) ? $location : null,
            'customer_location' => is_array($location) ? $location : null,
            'payment' => [
                'method' => trim((string) ($draft['payment_method'] ?? '')) ?: null,
                'status' => data_get($context, 'active_order.payment_status'),
            ],
            'review' => [
                'required' => false,
                'reason' => null,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $context @return list<string> */
    private function missingForInvalidItems(array $items, array $context): array
    {
        $missing = [];
        $menu = collect((array) data_get($context, 'menu', []))->keyBy('id');
        foreach ($items as $item) {
            if (! is_array($item) || ($item['valid'] ?? true) !== false) {
                continue;
            }
            if ((array) ($item['daily_component_candidates'] ?? []) !== []) {
                $missing[] = 'ACOMPANHAMENTO';
            }
            $product = $menu->get((int) ($item['menu_item_id'] ?? 0));
            $rule = is_array($product) ? (string) ($product['rule'] ?? '') : '';
            $slug = (string) ($item['menu_item_slug'] ?? '');
            $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
            $meats = collect([
                $selections['meat'] ?? null,
                ...(array) ($selections['meats'] ?? []),
            ])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->values();
            $requiresMeat = in_array($rule, ['n8_tradicional', 'n9_tradicional'], true)
                || in_array($slug, ['n8-tradicional', 'n9-tradicional'], true)
                || (int) data_get($product, 'resolved_configuration.meat_selection.min', 0) > 0;
            if ($requiresMeat && ($selections['meat_mode'] ?? null) !== 'none' && $meats->isEmpty()) {
                $missing[] = 'CARNE';
            }
        }

        return array_values(array_unique($missing));
    }

    /** @param array<string,mixed> $previous @return list<string> */
    private function requiredPreviousMissing(array $previous): array
    {
        $missing = $this->codes((array) ($previous['required_missing_slots'] ?? $previous['missing_fields'] ?? $previous['missing_slots'] ?? []));

        return array_values(array_diff($missing, [
            'ITEM_CONFIRMATION', 'MORE_ITEMS', 'PAYMENT_PROOF', 'PAYMENT_CONFIRMATION', 'CONSTRAINT',
        ]));
    }

    /** @param array<string,mixed> $previous @param array<string,mixed> $candidate @return array<string,mixed> */
    private function draft(array $previous, array $candidate, bool $readOnly): array
    {
        if ($readOnly) {
            return $previous;
        }

        $draft = (array) ($candidate['items'] ?? []) !== [] ? $candidate : $previous;
        foreach (['fulfillment', 'address', 'payment_method'] as $key) {
            $value = $candidate[$key] ?? null;
            if ($value !== null && $value !== '') {
                $draft[$key] = $value;
            } elseif (! array_key_exists($key, $draft)) {
                $draft[$key] = $previous[$key] ?? ($key === 'fulfillment' ? null : '');
            }
        }
        $draft['items'] = array_values((array) ($draft['items'] ?? []));

        return $draft;
    }

    /** @param list<array<string,mixed>> $items @param list<string> $missing @param list<array<string,mixed>> $constraints @param array<string,mixed> $draft @param array<string,mixed> $context @return array{string,?string} */
    private function position(bool $hasContext, array $items, array $missing, array $constraints, bool $confirmed, string $moreItems, array $draft, array $context): array
    {
        if (! $hasContext) {
            return ['DISCOVERY', null];
        }
        if ($items === [] || array_intersect($missing, ['PRODUCT', 'MENU_ITEM', 'N8_VARIANT']) !== []) {
            return ['SELECT_PRODUCT', 'ASK_PRODUCT'];
        }
        if ($constraints !== []) {
            return ['RESOLVE_CONSTRAINT', 'RESOLVE_CONSTRAINT'];
        }
        if ($missing !== []) {
            $objective = $this->objectiveForMissing($missing[0]);
            $phase = in_array($objective, ['ASK_FULFILLMENT', 'ASK_LOCATION', 'ASK_PAYMENT_METHOD'], true)
                ? $objective
                : 'BUILD_ITEM';

            return [$phase, $objective];
        }
        if (! $confirmed) {
            return ['CONFIRM_ITEM', 'CONFIRM_ITEM'];
        }
        if ($moreItems === 'accepted') {
            return ['SELECT_PRODUCT', 'ASK_PRODUCT'];
        }
        if ($moreItems !== 'declined') {
            return ['ASK_MORE_ITEMS', 'ASK_MORE_ITEMS'];
        }

        $fulfillment = (string) ($draft['fulfillment'] ?? '');
        if ($fulfillment === '') {
            return ['ASK_FULFILLMENT', 'ASK_FULFILLMENT'];
        }

        $activeOrder = data_get($context, 'active_order');
        $paymentMethod = trim((string) ($draft['payment_method'] ?? ''));
        if (is_array($activeOrder)) {
            if ((string) ($activeOrder['status'] ?? '') === 'payment_proof_received') {
                return ['WAIT_HUMAN_PAYMENT_CONFIRMATION', 'WAIT_HUMAN_PAYMENT_CONFIRMATION'];
            }
            if ((string) ($activeOrder['payment_status'] ?? '') === 'paid') {
                return ['COMPLETE', null];
            }
            if ($paymentMethod === 'pix') {
                return ['WAIT_PAYMENT_PROOF', 'WAIT_PAYMENT_PROOF'];
            }

            return ['ASK_PAYMENT_METHOD', 'ASK_PAYMENT_METHOD'];
        }
        if ($fulfillment === 'delivery') {
            $hasCoordinates = is_numeric(data_get($context, 'latest_message.location.latitude'))
                && is_numeric(data_get($context, 'latest_message.location.longitude'));
            if (trim((string) ($draft['address'] ?? '')) === '' && ! $hasCoordinates) {
                return ['ASK_LOCATION', 'ASK_LOCATION'];
            }

            return ['CALCULATE_DELIVERY', 'CALCULATE_DELIVERY'];
        }

        return ['ASK_PAYMENT_METHOD', 'ASK_PAYMENT_METHOD'];
    }

    private function objectiveForMissing(string $code): string
    {
        return match (strtoupper($code)) {
            'CARNE' => 'ASK_MEAT',
            'SALADA' => 'ASK_SALAD',
            'ACOMPANHAMENTO' => 'ASK_COMPONENTS',
            'ADDRESS' => 'ASK_LOCATION',
            'PAYMENT_METHOD' => 'ASK_PAYMENT_METHOD',
            'FULFILLMENT' => 'ASK_FULFILLMENT',
            'VALID_QUANTITY' => 'ASK_QUANTITY',
            'SABOR' => 'ASK_FLAVOR',
            default => 'ASK_PRODUCT',
        };
    }

    /** @return array<string,mixed>|null */
    /** @param list<int> $offeredProductIds */
    private function assistantGoal(?string $objective, array $draft, array $context, array $offeredProductIds): ?array
    {
        $slot = match ($objective) {
            'ASK_PRODUCT' => 'product',
            'ASK_MEAT' => 'carne',
            'ASK_SALAD' => 'salada',
            'ASK_COMPONENTS' => 'acompanhamento',
            'ASK_QUANTITY' => 'quantity',
            'ASK_FLAVOR' => 'sabor',
            'CONFIRM_ITEM' => 'item_confirmation',
            'ASK_MORE_ITEMS' => 'more_items',
            'ASK_FULFILLMENT' => 'fulfillment',
            'ASK_LOCATION' => 'address',
            'ASK_PAYMENT_METHOD' => 'payment_method',
            'WAIT_PAYMENT_PROOF' => 'payment_proof',
            'WAIT_HUMAN_PAYMENT_CONFIRMATION' => 'payment_confirmation',
            'RESOLVE_CONSTRAINT' => 'constraint',
            default => null,
        };
        if ($slot === null) {
            return null;
        }

        $allowed = match ($slot) {
            'product' => $this->productAllowedValues($context, $offeredProductIds),
            'carne' => collect((array) data_get($context, 'daily_meats', []))
                ->filter(fn (mixed $meat): bool => is_array($meat) && filled($meat['name'] ?? null))
                ->map(fn (array $meat): array => [
                    'id' => (string) ($meat['id'] ?? $meat['component_id'] ?? ''),
                    'label' => (string) $meat['name'],
                    'aliases' => array_values(array_filter([(string) ($meat['slug'] ?? '')])),
                    'state_delta' => ['items.0.selections.meats' => [(string) $meat['name']]],
                ])->values()->all(),
            'fulfillment' => [
                ['id' => 'pickup', 'label' => 'Retirada', 'aliases' => ['retirar', 'buscar'], 'state_delta' => ['fulfillment' => 'pickup']],
                ['id' => 'delivery', 'label' => 'Entrega', 'aliases' => ['entregar'], 'state_delta' => ['fulfillment' => 'delivery']],
            ],
            'payment_method' => collect((array) data_get($context, 'payment.available_methods', []))
                ->map(fn (mixed $method): array => [
                    'id' => (string) $method,
                    'label' => Str::headline((string) $method),
                    'aliases' => [],
                    'state_delta' => ['payment_method' => (string) $method],
                ])->values()->all(),
            default => $this->canonicalAllowedValues($slot, $draft, $context),
        };

        return [
            'type' => match (true) {
                in_array($slot, ['address', 'payment_proof'], true) => 'provide_value',
                $slot === 'payment_confirmation' => 'wait',
                in_array($slot, ['item_confirmation', 'more_items'], true) => 'confirm',
                default => 'choose_option',
            },
            'slot' => $slot,
            'product_id' => (int) data_get($draft, 'items.0.menu_item_id') ?: null,
            'allowed_values' => $allowed,
        ];
    }

    /** @param list<int> $offeredProductIds @return list<array<string,mixed>> */
    private function productAllowedValues(array $context, array $offeredProductIds): array
    {
        $ids = collect($offeredProductIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique();

        return collect((array) data_get($context, 'menu', []))
            ->filter(fn (mixed $product): bool => is_array($product) && $ids->contains((int) ($product['id'] ?? 0)))
            ->map(function (array $product): array {
                $item = [
                    'menu_item_id' => (int) $product['id'],
                    'menu_item_slug' => (string) ($product['slug'] ?? ''),
                    'quantity' => 1,
                    'selections' => ['meat' => null, 'meats' => []],
                    'daily_component_ids' => [],
                    'removed_components' => [],
                    'item_notes' => '',
                ];

                return [
                    'id' => (string) $product['id'],
                    'label' => (string) ($product['name'] ?? ''),
                    'aliases' => array_values(array_filter([
                        (string) ($product['slug'] ?? ''),
                        Str::afterLast((string) ($product['name'] ?? ''), ' '),
                    ])),
                    'state_delta' => ['items.0' => $item],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function candidateVariantGoal(array $candidate, array $context): array
    {
        $ids = collect((array) ($candidate['variant_candidates'] ?? []))->map(fn (mixed $id): int => (int) $id)->filter()->unique();
        $allowed = collect((array) data_get($context, 'menu', []))
            ->filter(fn (mixed $product): bool => is_array($product) && $ids->contains((int) ($product['id'] ?? 0)))
            ->map(function (array $product) use ($candidate): array {
                $item = [
                    'menu_item_id' => (int) $product['id'],
                    'menu_item_slug' => (string) ($product['slug'] ?? ''),
                    'quantity' => max(1, (int) ($candidate['quantity'] ?? 1)),
                    'selections' => ['meat' => null, 'meats' => array_values((array) ($candidate['meats'] ?? []))],
                    'daily_component_ids' => array_values((array) ($candidate['daily_component_ids'] ?? [])),
                    'removed_components' => [],
                    'item_notes' => (string) ($candidate['notes'] ?? ''),
                    'candidate_origin' => ['family' => (string) ($candidate['family'] ?? ''), 'provenance' => (string) ($candidate['provenance'] ?? '')],
                ];

                return [
                    'id' => (string) $product['id'],
                    'label' => (string) ($product['name'] ?? ''),
                    'aliases' => array_values(array_filter([(string) ($product['slug'] ?? ''), Str::afterLast((string) ($product['name'] ?? ''), ' ')])),
                    'state_delta' => ['items.0' => $item],
                ];
            })->values()->all();

        return ['type' => 'choose_option', 'slot' => 'product_variant', 'product_id' => null, 'allowed_values' => $allowed];
    }

    /** @param array<string,mixed> $draft @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function canonicalAllowedValues(string $slot, array $draft, array $context): array
    {
        $item = data_get($draft, 'items.0');
        if (! is_array($item)) {
            return [];
        }
        if ($slot === 'acompanhamento') {
            $candidateIds = collect((array) ($item['daily_component_candidates'] ?? []))
                ->flatMap(fn (mixed $candidate): array => is_array($candidate) ? (array) ($candidate['component_ids'] ?? []) : [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique();

            return collect((array) data_get($context, 'operational_catalog.components', []))
                ->filter(fn (mixed $component): bool => is_array($component)
                    && $candidateIds->contains((int) ($component['id'] ?? 0)))
                ->map(fn (array $component): array => [
                    'id' => (string) ($component['id'] ?? ''),
                    'label' => (string) ($component['name'] ?? ''),
                    'aliases' => array_values(array_filter([(string) ($component['slug'] ?? '')])),
                    'state_delta' => ['items.0.daily_component_ids' => [(int) ($component['id'] ?? 0)]],
                ])->values()->all();
        }

        $product = collect((array) data_get($context, 'menu', []))->firstWhere('id', (int) ($item['menu_item_id'] ?? 0));
        if (! is_array($product)) {
            return [];
        }
        $group = collect((array) ($product['groups'] ?? []))->first(fn (mixed $candidate): bool => is_array($candidate)
            && Str::of((string) ($candidate['code'] ?? ''))->ascii()->lower()->toString() === $slot);
        if (! is_array($group)) {
            return [];
        }

        return collect((array) ($group['options'] ?? []))
            ->filter(fn (mixed $option): bool => is_array($option) && filled($option['name'] ?? null))
            ->map(fn (array $option): array => [
                'id' => (string) ($option['id'] ?? ''),
                'label' => (string) $option['name'],
                'aliases' => array_values(array_filter([(string) ($option['slug'] ?? '')])),
                'state_delta' => ["items.0.selections.{$slot}" => (string) $option['name']],
            ])->values()->all();
    }

    /** @param list<string> $missing @return list<string> */
    private function lifecycleMissing(?string $objective, array $missing): array
    {
        if ($missing !== []) {
            return array_values(array_unique($missing));
        }

        $code = match ($objective) {
            'CONFIRM_ITEM' => 'ITEM_CONFIRMATION',
            'ASK_MORE_ITEMS' => 'MORE_ITEMS',
            'ASK_FULFILLMENT' => 'FULFILLMENT',
            'ASK_LOCATION' => 'ADDRESS',
            'ASK_PAYMENT_METHOD' => 'PAYMENT_METHOD',
            'WAIT_PAYMENT_PROOF' => 'PAYMENT_PROOF',
            'WAIT_HUMAN_PAYMENT_CONFIRMATION' => 'PAYMENT_CONFIRMATION',
            'RESOLVE_CONSTRAINT' => 'CONSTRAINT',
            default => null,
        };

        return $code === null ? [] : [$code];
    }

    /** @return array<string,mixed> */
    private function resolvedSlots(array $draft, bool $confirmed, string $moreItems): array
    {
        return [
            'product' => (array) ($draft['items'] ?? []) !== [],
            'item_confirmation' => $confirmed,
            'more_items' => $moreItems === 'declined',
            'fulfillment' => filled($draft['fulfillment'] ?? null),
            'address' => filled($draft['address'] ?? null),
            'payment_method' => filled($draft['payment_method'] ?? null),
        ];
    }

    /** @return list<string> */
    private function codes(array $entries): array
    {
        return collect($entries)->map(function (mixed $entry): string {
            $value = is_array($entry) ? data_get($entry, 'code', '') : $entry;

            return strtoupper(trim((string) $value));
        })->filter()->unique()->values()->all();
    }

    /** @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    /** @return list<array<string,mixed>> */
    private function candidateItems(array $analysis, array $previous, array $selectedItems): array
    {
        if ($selectedItems !== []) {
            return [];
        }

        $candidate = data_get($analysis, 'metadata.candidate_item');
        if (is_array($candidate) && filled($candidate['family'] ?? null)) {
            return [[
                ...$candidate,
                'status' => 'candidate',
                'variant' => null,
            ]];
        }

        return array_values((array) ($previous['candidate_items'] ?? []));
    }

    /** @return list<array<string,mixed>> */
    private function projectSelectedComponents(array $draft, array $candidateItems, array $context, array $analysis): array
    {
        if ($candidateItems !== []) {
            return collect($candidateItems)
                ->flatMap(fn (array $candidate): array => (array) ($candidate['components'] ?? []))
                ->filter(fn (mixed $component): bool => is_array($component) && (int) ($component['id'] ?? 0) > 0)
                ->unique('id')->values()->all();
        }

        $recognized = collect((array) data_get($analysis, 'metadata.recognized_components', []))
            ->filter(fn (mixed $component): bool => is_array($component) && (int) ($component['id'] ?? 0) > 0)
            ->unique('id')
            ->values();
        if ($recognized->isNotEmpty()) {
            return $recognized->all();
        }

        $catalog = collect((array) data_get($context, 'operational_catalog.components', []))->keyBy('id');
        $ids = collect((array) ($draft['items'] ?? []))
            ->flatMap(function (mixed $item): array {
                if (! is_array($item)) {
                    return [];
                }

                return [
                    ...(array) ($item['daily_component_ids'] ?? []),
                    ...collect((array) data_get($item, 'canonical_entity_resolution.meats.resolved', []))
                        ->pluck('canonical_id')->all(),
                ];
            })
            ->map(fn (mixed $id): int => (int) $id)->filter()->unique();

        return $ids->map(function (int $id) use ($catalog): array {
            $component = $catalog->get($id, []);

            return [
                'id' => $id,
                'name' => (string) data_get($component, 'name', ''),
                'type' => (string) data_get($component, 'type', ''),
                'source' => 'active_order_state',
            ];
        })->values()->all();
    }
}
