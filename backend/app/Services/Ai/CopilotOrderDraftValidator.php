<?php

namespace App\Services\Ai;

use App\Data\Ai\CopilotAnalysis;
use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use App\Models\Company;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class CopilotOrderDraftValidator
{
    public function __construct(
        private readonly CopilotMenuAliasResolver $aliases,
        private readonly CopilotOrderItemSelectionAdapter $selections,
        private readonly CopilotCanonicalIdentity $identity,
        private readonly CopilotQuantityGroundingGuard $quantities,
        private readonly CopilotItemNoteGroundingGuard $notes,
        private readonly CopilotPreparationNoteRecovery $preparationNotes,
        private readonly CopilotProductGroundingGuard $products,
        private readonly CopilotIntentGroundingGuard $intents,
        private readonly CopilotProductEligibility $eligibility,
        private readonly CopilotRemovalGroundingGuard $removals,
    ) {}

    /** @param array<string,mixed> $context */
    public function validate(Company $company, CopilotAnalysis $analysis, ?CarbonInterface $date = null, array $context = []): CopilotAnalysis
    {
        $date ??= CarbonImmutable::today();
        $latestIntent = (string) data_get($context, 'latest_intent', $analysis->intent);
        if (array_key_exists('latest_intent', $context)
            && in_array($latestIntent, ['MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'GENERAL_MESSAGE'], true)
            && ! in_array($analysis->intent, ['HUMAN_REQUEST', 'UNKNOWN'], true)
            && empty($analysis->draftOrder['items'] ?? [])) {
            return new CopilotAnalysis(
                $latestIntent,
                $analysis->confidence,
                $analysis->summary,
                [...$analysis->draftOrder, 'items' => []],
                [],
                $analysis->warnings,
                $analysis->suggestedReply,
                true,
                $analysis->metadata,
                $analysis->replyMessages,
            );
        }
        if (data_get($analysis->metadata, 'semantic_read_only') === true) {
            return new CopilotAnalysis(
                $analysis->intent,
                $analysis->confidence,
                $analysis->summary,
                ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                [],
                $analysis->warnings,
                $analysis->suggestedReply,
                true,
                $analysis->metadata,
                $analysis->replyMessages,
            );
        }
        $candidateDraft = $this->mergeCandidateItem(
            $this->mergePendingDraft($analysis->draftOrder, $context, $latestIntent, $analysis->metadata),
            $context,
        );
        $selectionMessages = $this->selectionMessages($context, $latestIntent);
        $explicitSelectionMessages = $this->currentTriggerMessages($context);
        $applyNewOrderHistoryBoundary = $latestIntent === 'ORDER_CREATE' && data_get($context, 'active_order') === null;
        $historicalMessages = $applyNewOrderHistoryBoundary
            ? $this->historicalInboundMessages(data_get($context, 'messages', []))
            : [];
        $warnings = $analysis->warnings;
        $invalidQuantityDiscarded = false;
        $ungroundedProductDiscarded = false;
        $pendingClarification = data_get($context, 'pending_clarification');
        $hasPendingMeatClarification = is_array($pendingClarification)
            && ($pendingClarification['status'] ?? null) === 'eligible'
            && ($pendingClarification['type'] ?? null) === 'ambiguous_meat';
        $resolvedPendingClarification = $this->resolvedPendingClarification($pendingClarification);
        $pendingProductIds = collect((array) data_get($context, 'pending_order_state.draft_order.items', []))
            ->map(fn (mixed $item): int => is_array($item) ? (int) ($item['menu_item_id'] ?? 0) : 0)
            ->filter()
            ->unique()
            ->all();
        $semanticGroundedProductIds = collect((array) data_get($analysis->metadata, 'semantic_grounded_product_ids', []));
        if (data_get($analysis->metadata, 'semantic_delta_validated') === true
            && in_array((string) data_get($analysis->metadata, 'semantic_slot', ''), ['product', 'product_variant'], true)) {
            $semanticGroundedProductIds = $semanticGroundedProductIds->merge(
                (array) data_get($analysis->metadata, 'semantic_allowed_value_ids', []),
            );
        }
        $semanticGroundedProductIds = $semanticGroundedProductIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->all();
        if ($this->hasAmbiguousPendingMeatClarification($pendingClarification)) {
            $warnings[] = [
                'code' => 'AMBIGUOUS_MEAT',
                'message' => 'A escolha de carne permanece ambigua no contexto do cliente.',
            ];
        }
        if ($resolvedPendingClarification !== null) {
            $selectionMessages[] = $this->resolvedPendingSelectionMessage($pendingClarification);
        }
        $proposedItems = $resolvedPendingClarification === null
            ? ($candidateDraft['items'] ?? [])
            : [$this->resolvedPendingItem($pendingClarification)];
        $discardedN8Indexes = [];
        $validateItem = function (array $item, int $index) use ($company, $date, $selectionMessages, $explicitSelectionMessages, $latestIntent, $applyNewOrderHistoryBoundary, $historicalMessages, $hasPendingMeatClarification, $resolvedPendingClarification, $pendingProductIds, $semanticGroundedProductIds, $analysis, &$warnings, &$invalidQuantityDiscarded, &$ungroundedProductDiscarded, &$discardedN8Indexes): ?array {
            $providerItem = $item;
            if ($applyNewOrderHistoryBoundary) {
                $item = $this->withoutHistoricalOnlyRemovals($item, $selectionMessages, $historicalMessages);
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 50) {
                $warnings[] = ['code' => 'INVALID_QUANTITY', 'message' => 'A quantidade sugerida nao e valida.', 'item_index' => $index];
                $invalidQuantityDiscarded = true;

                return null;
            }
            $product = $this->aliases->resolve($company, $item['menu_item_id'] ?? null, (string) ($item['menu_item_slug'] ?? ''));
            if (! $product && $this->key((string) ($item['menu_item_slug'] ?? '')) === 'n8') {
                $recoveredN8 = $this->products->recoverN8Traditional($company, $explicitSelectionMessages, $date);
                $product = $recoveredN8 === null
                    ? null
                    : $this->aliases->resolve($company, (int) $recoveredN8['menu_item_id'], '');
            }
            if (! $product) {
                $groundingSource = $latestIntent === 'ORDER_CREATE' ? $explicitSelectionMessages : $selectionMessages;
                $groundingText = collect($groundingSource)
                    ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound')
                    ->pluck('body')
                    ->implode(' ');
                $product = $this->aliases->resolveFromTextIncludingInactive($company, $groundingText);
            }
            if (! $product || ! $product->is_active || ! $product->is_available_by_default) {
                $warnings[] = ['code' => 'UNRESOLVED_MENU_ITEM', 'message' => 'Nao foi possivel associar o item a um produto disponivel.', 'item_index' => $index];
                $ungroundedProductDiscarded = true;

                return null;
            }
            if ($applyNewOrderHistoryBoundary) {
                $item = $this->withoutHistoricalOnlyFreeAssemblySaladOptOut($product, $item, $selectionMessages, $historicalMessages);
            }
            $semanticallyGrounded = in_array((int) $product->id, $semanticGroundedProductIds, true);
            $pendingStateGrounded = in_array($latestIntent, ['ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)
                && in_array((int) $product->id, $pendingProductIds, true);
            if (! $semanticallyGrounded && ! $pendingStateGrounded && ! $this->products->isGrounded($product, $selectionMessages)) {
                $warnings[] = ['code' => 'UNGROUNDED_PRODUCT', 'message' => 'O produto sugerido nao possui evidencia suficiente na mensagem do cliente.', 'item_index' => $index];
                $ungroundedProductDiscarded = true;
                if ($product->menu_rule_code === 'n8_casa') {
                    $discardedN8Indexes[] = $index;
                }

                return null;
            }
            if ($latestIntent === 'ORDER_CONTINUE' && $hasPendingMeatClarification && $resolvedPendingClarification === null) {
                $item['selections'] = [...(is_array($item['selections'] ?? null) ? $item['selections'] : []), 'meat' => null, 'meats' => []];
            } elseif ($resolvedPendingClarification === null) {
                $item = $this->products->enrichN8Traditional($company, $product, $date, $item, $explicitSelectionMessages);
                if (data_get($analysis->metadata, 'semantic_pending_order_correction') !== true) {
                    $item = $this->selections->recoverExplicitDailyMeats($company, $product, $date, $item, $explicitSelectionMessages);
                }
            }
            $dailyComponents = $this->selections->recoverExplicitDailyComponents($company, $product, $date, $item, $explicitSelectionMessages);
            $item = $dailyComponents['item'];
            $warnings = [...$warnings, ...$dailyComponents['warnings']];
            $item = $this->selections->recoverExplicitFreeAssemblySaladOptOut($company, $product, $date, $item, $explicitSelectionMessages);
            if ($this->hasHouseSaladDelegation($selectionMessages) && trim((string) ($item['item_notes'] ?? '')) === '') {
                $item['item_notes'] = 'Salada à escolha da casa';
            }
            if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)
                && $this->hasExplicitWithoutMeat($this->products->selectionText($product, $explicitSelectionMessages))) {
                $item['selections'] = [...(is_array($item['selections'] ?? null) ? $item['selections'] : []), 'meat_mode' => 'none', 'meat' => null, 'meats' => [], 'extra_beef' => 0];
            }
            $result = $this->selections->validate($company, $product, $date, [
                ...$item,
                'menu_item_id' => $product->id,
                'menu_item_slug' => $product->slug,
                'quantity' => $quantity,
                'item_notes' => Str::limit((string) ($item['item_notes'] ?? ''), 500, ''),
            ], $selectionMessages);
            foreach ($result['warnings'] as $warning) {
                $warnings[] = [...$warning, 'item_index' => $index];
            }
            if ($this->isFreeAssemblySaladOptOut($product, $result['item'])
                || $this->discardedHistoricalFreeAssemblySaladOptOut($product, $providerItem, $item)) {
                $warnings = array_values(array_filter($warnings, fn (array $warning): bool => strtoupper((string) ($warning['code'] ?? '')) !== 'REMOVAL_NOT_SUPPORTED'));
            }

            $priceWarning = $this->priceWarning($product, $this->products->selectionText($product, $explicitSelectionMessages));
            if ($priceWarning !== null) {
                $warnings[] = [...$priceWarning, 'item_index' => $index];
            }

            return $result['item'];
        };
        $items = collect($proposedItems)->map($validateItem)->filter()->values()->all();

        // Recover each explicit product reference independently. A rejected N8 Casa must not erase an
        // explicit N8 Livre just because another item in the same message survived validation.
        $recoveredItems = $this->products->recoverExplicitItems($company, $explicitSelectionMessages, $date);
        foreach ($recoveredItems as $recovered) {
            if (collect($items)->contains(fn (array $item): bool => (int) ($item['menu_item_id'] ?? 0) === (int) $recovered['menu_item_id'])) {
                continue;
            }

            $validated = $validateItem($recovered, count($proposedItems));
            if ($validated !== null) {
                $items[] = $validated;
            }
        }
        if ($discardedN8Indexes !== [] && collect($items)->contains(fn (array $item): bool => ($item['menu_item_slug'] ?? null) === 'n8-tradicional')) {
            $warnings = array_values(array_filter($warnings, fn (array $warning): bool => ! (
                ($warning['code'] ?? null) === 'UNGROUNDED_PRODUCT'
                && in_array((int) ($warning['item_index'] ?? -1), $discardedN8Indexes, true)
            )));
            $ungroundedProductDiscarded = false;
        }
        $items = $this->products->orderByExplicitReferences($company, $items, $explicitSelectionMessages);
        $grounded = $this->quantities->ground($company, $items, data_get($context, 'messages', []));
        $items = $grounded['items'];
        $warnings = [...$warnings, ...$grounded['warnings']];
        $items = $this->preparationNotes->recover($items, data_get($context, 'messages', []));
        $groundedNotes = $this->notes->ground($items, data_get($context, 'messages', []));
        $items = $groundedNotes['items'];
        $warnings = [...$warnings, ...$groundedNotes['warnings']];
        $missing = $this->cleanupDependentMissing($company, [
            ...$this->recognizedProviderMissing($analysis->missingInformation),
            ...($ungroundedProductDiscarded && ! $this->products->hasGroundedProductReference($company, $selectionMessages) ? [['code' => 'PRODUCT', 'label' => 'Produto']] : []),
            ...($invalidQuantityDiscarded ? [['code' => 'VALID_QUANTITY', 'label' => 'Quantidade valida']] : []),
            ...$grounded['missing'],
            ...(collect($warnings)->contains(fn (array $warning): bool => ($warning['code'] ?? null) === 'INVALID_EXTRA_BEEF') ? [['code' => 'EXTRA_BEEF_QUANTITY', 'label' => 'Quantidade de bife adicional']] : []),
            ...collect($items)->flatMap(fn (array $item, int $index): array => $this->missingCustomerSelectionsForResolvedItem($company, $item, $index))->all(),
            ...(collect($items)->contains(fn (array $item): bool => (array) ($item['daily_component_candidates'] ?? []) !== [])
                ? [['code' => 'ACOMPANHAMENTO', 'label' => 'Acompanhamento']]
                : []),
        ], $items);
        $draftOrder = $this->continueDraft($candidateDraft, $latestIntent, $selectionMessages);
        if (($draftOrder['fulfillment'] ?? null) === 'delivery' && blank($draftOrder['address'] ?? null)) {
            $missing[] = ['code' => 'ADDRESS', 'label' => 'Endereco'];
        }
        if ($this->hasHouseSaladDelegation($selectionMessages)) {
            $missing = array_values(array_filter($missing, fn (array $entry): bool => strtoupper((string) ($entry['code'] ?? '')) !== 'SALADA'));
        }
        $missing = $this->intents->refineMissing($missing, $items, $context);
        $constraintResolution = $this->meatConstraints($company, $items);
        $items = $constraintResolution['items'];
        $missing = $this->identity->missing([...$missing, ...$constraintResolution['missing']], $this->hasResolvedN8Variant($items));
        $warnings = $this->dedupeWarnings([...$warnings, ...$constraintResolution['warnings']]);

        return new CopilotAnalysis(
            $this->intents->finalize($analysis->intent, $items, $missing, $warnings, $context),
            $analysis->confidence,
            $analysis->summary,
            [...$draftOrder, 'items' => $items],
            $missing,
            $warnings,
            $analysis->suggestedReply,
            true,
            [
                ...$analysis->metadata,
                'validation_warning_count' => count($warnings),
                'constraints' => $constraintResolution['constraints'],
            ],
            $analysis->replyMessages,
        );
    }

    /**
     * Converts included-meat cardinality into a structured commercial result.
     * Additional pricing remains sourced from Product::composition_rules and
     * the unit price itself is still calculated by OrderItemSelectionValidator.
     *
     * @param  list<array<string,mixed>>  $items
     * @return array{items:list<array<string,mixed>>,constraints:list<array<string,mixed>>,missing:list<array<string,string>>,warnings:list<array<string,string>>}
     */
    private function meatConstraints(Company $company, array $items): array
    {
        $constraints = [];
        $missing = [];
        $warnings = [];

        foreach ($items as $index => &$item) {
            $product = Product::query()
                ->where('company_id', $company->id)
                ->whereKey((int) ($item['menu_item_id'] ?? 0))
                ->first();
            if (! $product instanceof Product || ! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
                continue;
            }

            $requested = collect((array) data_get($item, 'selections.meats', []))
                ->filter(fn (mixed $meat): bool => is_string($meat) && trim($meat) !== '')
                ->unique(fn (string $meat): string => Str::of($meat)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString())
                ->values();
            $configuredIncluded = max(0, (int) data_get($product->composition_rules, 'traditional_meat_selection.max_types', 0));
            $unitPrice = max(0, (int) data_get($product->composition_rules, 'standard_meat_additional_price_cents', 0));
            $additionalTotal = max(0, (int) data_get($item, 'canonical_selection_quote.standard_meat_additional_total_cents', 0));
            $additionalCount = $unitPrice > 0 ? intdiv($additionalTotal, $unitPrice) : 0;
            $included = $additionalCount > 0 ? max(0, $requested->count() - $additionalCount) : $configuredIncluded;
            if ($included < 1 || ($additionalCount === 0 && $requested->count() <= $configuredIncluded)) {
                continue;
            }

            $constraint = [
                'code' => 'MEAT_ALLOWANCE_EXCEEDED',
                'item_index' => $index,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'requested' => $requested->all(),
                'included_max' => $included,
                'additional_count' => $additionalCount,
                'additional_unit_price_cents' => $unitPrice,
                'additional_total_cents' => $additionalTotal,
                'commercial_options' => $unitPrice > 0 ? ['apply_canonical_additional', 'remove_selection'] : ['remove_selection'],
                'resolution' => $unitPrice > 0 ? 'additional_applied' : 'customer_choice_required',
            ];
            $constraints[] = $constraint;

            if ($unitPrice < 1) {
                $item['valid'] = false;
                $missing[] = ['code' => 'CARNE', 'label' => 'Carne'];
                $warnings[] = [
                    'code' => 'MEAT_ALLOWANCE_EXCEEDED',
                    'message' => 'A quantidade de carnes excede a cardinalidade canônica e exige escolha do cliente.',
                ];
            }
        }
        unset($item);

        return compact('items', 'constraints', 'missing', 'warnings');
    }

    /** @param array<string,mixed> $item @return list<array{code:string,label:string}> */
    private function missingCustomerSelections(Product $product, array $item): array
    {
        $product->loadMissing('optionGroups');
        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
        $removed = collect($item['removed_components'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => $this->key($value))
            ->all();

        $missing = $product->optionGroups
            ->filter(fn ($group): bool => $group->is_required && $group->selection_actor === ProductSelectionActor::Customer)
            ->reject(function ($group) use ($removed): bool {
                $groupKey = $this->key((string) $group->code);
                $labelKey = $this->key((string) $group->label);

                return in_array($groupKey, $removed, true)
                    || in_array($labelKey, $removed, true)
                    || in_array('sem'.$groupKey, $removed, true)
                    || in_array('sem'.$labelKey, $removed, true)
                    || ($groupKey === 'salada' && in_array('salada', $removed, true));
            })
            ->filter(function ($group) use ($selections, $product): bool {
                if ($group->code === 'carne'
                    && ($selections['meat_mode'] ?? null) === 'none'
                    && in_array('carne', data_get($product->composition_rules, 'allow_no_meat_group_codes', []), true)) {
                    return false;
                }
                $value = $selections[$group->code] ?? null;
                if ($group->code === 'carne') {
                    $single = $selections['meat'] ?? null;
                    $multiple = $selections['meats'] ?? [];
                    $value = $group->selection_mode === ProductSelectionMode::Multiple
                        ? $multiple
                        : $single;
                }

                return $value === null || $value === '' || $value === [];
            })
            ->map(fn ($group): array => ['code' => strtoupper((string) $group->code), 'label' => (string) $group->label])
            ->values()
            ->all();

        if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)
            && ! in_array($selections['meat_mode'] ?? 'traditional', ['beef_only', 'none'], true)
            && (
                count(array_filter($selections['meats'] ?? [], fn (mixed $meat): bool => is_string($meat) && $meat !== '')) < 1
                || (bool) ($selections['meat_selection_pending'] ?? false)
            )) {
            $missing[] = ['code' => 'CARNE', 'label' => 'Carnes'];
        }

        return $missing;
    }

    /** @param array<string,mixed> $item @return list<array{code:string,label:string}> */
    private function missingCustomerSelectionsForResolvedItem(Company $company, array $item, int $index): array
    {
        if ((int) ($item['quantity'] ?? 0) < 1) {
            return [];
        }

        $product = $this->aliases->resolve($company, $item['menu_item_id'] ?? null, (string) ($item['menu_item_slug'] ?? ''));

        return $product ? $this->missingCustomerSelections($product, $item) : [];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function hasExplicitWithoutMeat(string $text): bool
    {
        return str_contains(Str::of($text)->ascii()->lower()->toString(), 'sem carne')
            || preg_match('/\bnao\s+(?:quero|quero)\s+carne\b/i', $text) === 1;
    }

    /** @param array<string,mixed> $item */
    private function isFreeAssemblySaladOptOut(Product $product, array $item): bool
    {
        return in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)
            && data_get($item, 'selections.salada') === 'none';
    }

    /** @param array<string,mixed> $providerItem @param array<string,mixed> $item */
    private function discardedHistoricalFreeAssemblySaladOptOut(Product $product, array $providerItem, array $item): bool
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return false;
        }

        $providerRemovals = collect($providerItem['removed_components'] ?? [])
            ->filter(fn (mixed $removed): bool => is_string($removed) && trim($removed) !== '');
        if ($providerRemovals->isEmpty()
            || ! $providerRemovals->every(fn (string $removed): bool => $this->key($removed) === 'salada')) {
            return false;
        }

        return collect($item['removed_components'] ?? [])
            ->filter(fn (mixed $removed): bool => is_string($removed) && $this->key($removed) === 'salada')
            ->isEmpty();
    }

    /** @param list<array{code:string,label:string}> $missing @return list<array{code:string,label:string}> */
    private function recognizedProviderMissing(array $missing): array
    {
        $allowed = ['PRODUCT', 'MENU_ITEM', 'PREVIOUS_ORDER_REFERENCE', 'VALID_QUANTITY', 'CARNE', 'SALADA', 'ADDRESS', 'N8_VARIANT', 'EXTRA_BEEF_QUANTITY', 'ACOMPANHAMENTO'];

        return array_values(array_filter($missing, fn (array $item): bool => in_array(strtoupper((string) ($item['code'] ?? '')), $allowed, true)));
    }

    /** @param list<array{code:string,label:string}> $missing @param list<array<string,mixed>> $items @return list<array{code:string,label:string}> */
    private function cleanupDependentMissing(Company $company, array $missing, array $items): array
    {
        $dependent = ['CARNE', 'SALADA', 'EXTRA_BEEF_QUANTITY', 'N8_VARIANT', 'SABOR', 'ACOMPANHAMENTO'];
        if ($items === []) {
            $hasUnresolvedProduct = collect($missing)->contains(fn (array $item): bool => in_array(strtoupper((string) ($item['code'] ?? '')), ['PRODUCT', 'MENU_ITEM'], true));

            return array_values(array_filter($missing, function (array $item) use ($dependent, $hasUnresolvedProduct): bool {
                $code = strtoupper((string) ($item['code'] ?? ''));

                return ! in_array($code, $dependent, true)
                    || ($code === 'CARNE' && $hasUnresolvedProduct);
            }));
        }

        $products = $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->whereIn('id', collect($items)->pluck('menu_item_id')->filter()->unique()->all())
            ->with('optionGroups')
            ->get()
            ->keyBy('id');

        return array_values(array_filter($missing, function (array $item) use ($dependent, $items, $products): bool {
            $code = strtoupper((string) ($item['code'] ?? ''));
            if (! in_array($code, $dependent, true)) {
                return true;
            }

            return collect($items)->contains(function (array $safeItem) use ($products, $code): bool {
                $product = $products->get($safeItem['menu_item_id'] ?? null);
                if (! $product) {
                    return false;
                }

                return match ($code) {
                    'CARNE' => collect($this->missingCustomerSelections($product, $safeItem))
                        ->contains(fn (array $missing): bool => strtoupper((string) ($missing['code'] ?? '')) === 'CARNE'),
                    'SALADA' => $product->optionGroups->contains(fn ($group): bool => strtoupper((string) $group->code) === 'SALADA'),
                    'N8_VARIANT' => in_array($product->menu_rule_code, ['n8_casa', 'n8_tradicional'], true),
                    default => true,
                };
            });
        }));
    }

    /** @param list<array<string,mixed>> $warnings @return list<array<string,mixed>> */
    private function dedupeWarnings(array $warnings): array
    {
        return collect($warnings)
            ->unique(function (array $warning): string {
                $code = strtoupper((string) ($warning['code'] ?? ''));

                return $code === 'INVALID_QUANTITY'
                    ? $code
                    : $code.'|'.Str::of((string) ($warning['message'] ?? ''))->ascii()->lower()->squish()->toString();
            })
            ->values()
            ->all();
    }

    /** @param list<array<string,mixed>> $items */
    private function hasResolvedN8Variant(array $items): bool
    {
        return collect($items)
            ->pluck('menu_item_slug')
            ->contains(fn (mixed $slug): bool => in_array($slug, ['n8-casa', 'n8-tradicional'], true));
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function selectionMessages(array $context, string $intent): array
    {
        $messages = array_values(data_get($context, 'messages', []));
        $trigger = data_get($context, 'trigger_message');
        $triggerMessage = is_array($trigger)
            && ($trigger['direction'] ?? null) === 'inbound'
            && ($trigger['type'] ?? 'text') === 'text'
                ? $trigger
                : collect($messages)->reverse()->first(fn (mixed $message): bool => is_array($message)
                    && ($message['direction'] ?? null) === 'inbound'
                    && ($message['type'] ?? 'text') === 'text');
        if ($intent === 'ORDER_CREATE'
            && data_get($context, 'pending_clarification.status') === 'eligible'
            && data_get($context, 'pending_clarification.type') === 'product_selection') {
            $latest = $triggerMessage;
            $recognized = collect(data_get($context, 'pending_clarification.recognized_components', []))
                ->pluck('name')
                ->filter()
                ->implode(', ');

            if (! is_array($latest)) {
                return $recognized === '' ? [] : [['direction' => 'inbound', 'type' => 'text', 'body' => $recognized]];
            }

            return [[
                'direction' => 'inbound',
                'type' => 'text',
                'body' => trim($recognized.' '.(string) ($latest['body'] ?? '')),
            ]];
        }

        if (in_array($intent, ['ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)) {
            $orderMessages = $intent === 'ORDER_CONFIRMATION'
                ? (is_array($triggerMessage) ? [$triggerMessage] : [])
                : $this->currentPendingOrderMessages($messages);
            $stateMessage = $this->pendingStateSelectionMessage($context);
            if ($stateMessage !== null) {
                array_unshift($orderMessages, $stateMessage);
            }

            return $orderMessages;
        }

        return is_array($triggerMessage) ? [$triggerMessage] : [];
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function currentTriggerMessages(array $context): array
    {
        $trigger = data_get($context, 'trigger_message');
        if (is_array($trigger)
            && ($trigger['direction'] ?? 'inbound') === 'inbound'
            && ($trigger['type'] ?? 'text') === 'text') {
            return [$trigger];
        }

        $latest = collect((array) data_get($context, 'messages', []))->reverse()->first(
            fn (mixed $message): bool => is_array($message)
                && ($message['direction'] ?? null) === 'inbound'
                && ($message['type'] ?? 'text') === 'text',
        );

        return is_array($latest) ? [$latest] : [];
    }

    /** @param array<string,mixed> $context @return array<string,string>|null */
    private function pendingStateSelectionMessage(array $context): ?array
    {
        $values = collect((array) data_get($context, 'pending_order_state.draft_order.items', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->flatMap(function (array $item): array {
                $selections = collect($item['selections'] ?? [])
                    ->flatten()
                    ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '' && $value !== 'none')
                    ->all();
                $validated = collect((array) ($item['validated_order_options'] ?? []))
                    ->pluck('name')
                    ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                    ->all();

                return [...$selections, ...$validated];
            })
            ->unique()
            ->values();
        if ($values->isEmpty()) {
            return null;
        }

        return ['direction' => 'inbound', 'type' => 'text', 'body' => $values->implode(', ')];
    }

    /** @param mixed $pending @return array<string, mixed>|null */
    private function resolvedPendingClarification(mixed $pending): ?array
    {
        if (! is_array($pending)
            || ($pending['status'] ?? null) !== 'eligible'
            || ($pending['type'] ?? null) !== 'ambiguous_meat'
            || data_get($pending, 'resolution.status') !== 'resolved'
            || (int) data_get($pending, 'resolution.component_id') < 1) {
            return null;
        }

        return $pending;
    }

    private function hasAmbiguousPendingMeatClarification(mixed $pending): bool
    {
        return is_array($pending)
            && ($pending['status'] ?? null) === 'eligible'
            && ($pending['type'] ?? null) === 'ambiguous_meat'
            && data_get($pending, 'resolution.status') === 'ambiguous';
    }

    /** @param array<string, mixed> $pending @return array<string, mixed> */
    private function resolvedPendingItem(array $pending): array
    {
        $componentId = (int) data_get($pending, 'resolution.component_id');
        $option = collect((array) ($pending['options'] ?? []))->firstWhere('component_id', $componentId);
        $selectionMode = (string) data_get($pending, 'scope.selection_mode');
        $displayName = (string) data_get($option, 'display_name');

        return [
            'menu_item_id' => (int) data_get($pending, 'candidate.product_id'),
            'menu_item_slug' => (string) data_get($pending, 'candidate.product_slug'),
            'quantity' => max(1, (int) data_get($pending, 'candidate.quantity', 1)),
            'selections' => [
                'meat_mode' => 'traditional',
                'meat' => $selectionMode === ProductSelectionMode::Single->value ? $displayName : null,
                'meats' => $selectionMode === ProductSelectionMode::Multiple->value ? [$displayName] : [],
            ],
            'removed_components' => [],
        ];
    }

    /** @param array<string, mixed> $pending @return array{direction:string,type:string,body:string} */
    private function resolvedPendingSelectionMessage(array $pending): array
    {
        $componentId = (int) data_get($pending, 'resolution.component_id');
        $option = collect((array) ($pending['options'] ?? []))->firstWhere('component_id', $componentId);
        $product = (string) data_get($pending, 'candidate.product_slug');

        // This evidence exists only after the customer's exact or ordinal answer was
        // matched against the prior event and revalidated against today's menu. Keeping
        // the product anchor here prevents the original ambiguous token from being used
        // again by the product-specific selection guards.
        return ['direction' => 'inbound', 'type' => 'text', 'body' => trim($product.' '.(string) data_get($option, 'display_name'))];
    }

    /**
     * Keep a fresh order from inheriting a removal that the provider only saw in an older turn.
     * Unknown removals stay intact so the selection adapter can still report them for human review.
     *
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $currentMessages
     * @param  list<array<string,mixed>>  $historicalMessages
     * @return array<string,mixed>
     */
    private function withoutHistoricalOnlyRemovals(array $item, array $currentMessages, array $historicalMessages): array
    {
        $removedComponents = $item['removed_components'] ?? [];
        if (! is_array($removedComponents) || $historicalMessages === []) {
            return $item;
        }

        $item['removed_components'] = array_values(array_filter($removedComponents, function (mixed $removed) use ($currentMessages, $historicalMessages): bool {
            if (! is_string($removed) || trim($removed) === '') {
                return true;
            }

            return $this->removals->isGrounded($removed, $currentMessages)
                || ! $this->removals->isGrounded($removed, $historicalMessages);
        }));

        return $item;
    }

    /**
     * A free-assembly "Sem salada" is a customer selection, not a component removal.
     * New orders may not inherit it from an earlier turn, while an ORDER_CONTINUE
     * deliberately keeps its pending-order selections outside this boundary.
     *
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $currentMessages
     * @param  list<array<string,mixed>>  $historicalMessages
     * @return array<string,mixed>
     */
    private function withoutHistoricalOnlyFreeAssemblySaladOptOut(Product $product, array $item, array $currentMessages, array $historicalMessages): array
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)
            || $historicalMessages === []) {
            return $item;
        }

        $selections = $item['selections'] ?? null;
        if (! is_array($selections)
            || ($selections['salada'] ?? null) !== 'none'
            || $this->removals->isGrounded('salada', $currentMessages)
            || ! $this->removals->isGrounded('salada', $historicalMessages)) {
            return $item;
        }

        unset($selections['salada']);

        return [...$item, 'selections' => $selections];
    }

    /** @param mixed $messages @return list<array<string,mixed>> */
    private function historicalInboundMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $messages = array_values($messages);
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text') {
                return array_values(array_filter(
                    array_slice($messages, 0, $index),
                    fn (mixed $candidate): bool => is_array($candidate)
                        && ($candidate['direction'] ?? null) === 'inbound'
                        && ($candidate['type'] ?? 'text') === 'text',
                ));
            }
        }

        return [];
    }

    /** @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    private function currentPendingOrderMessages(array $messages): array
    {
        $productIndex = null;
        foreach ($messages as $index => $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $body = Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->squish()->toString();
            $isMarmita = preg_match('/\bn\s*[- ]?\s*(?:5|8|9)\b/', $body) === 1;
            $isBeverage = preg_match('/\b(?:coca|guarana|sprite|mineiro|agua)\b/', $body) === 1;
            if ($isMarmita || ($productIndex === null && $isBeverage)) {
                $productIndex = $index;
            }
        }
        if ($productIndex === null) {
            return [];
        }

        // A delegation immediately before the product belongs to this pending turn, but older
        // history must not be allowed to revive a closed or unrelated order.
        $start = $productIndex;
        $previous = $messages[$productIndex - 1] ?? null;
        if (is_array($previous)
            && ($previous['direction'] ?? null) === 'inbound'
            && ($previous['type'] ?? 'text') === 'text'
            && $this->hasHouseSaladDelegation([$previous])) {
            $start--;
        }

        return array_values(array_slice($messages, $start));
    }

    /** @param list<array<string,mixed>> $messages */
    private function hasHouseSaladDelegation(array $messages): bool
    {
        $text = collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->implode(' ');
        $normalized = Str::of($text)->ascii()->lower()->squish()->toString();

        return preg_match('/(?:qualquer|a\s+escolha\s+da\s+casa).{0,32}salada|salada.{0,32}(?:qualquer|a\s+escolha\s+da\s+casa)|nao\s+tenho\s+preferencia/', $normalized) === 1;
    }

    /** @param array<string,mixed> $draft @param list<array<string,mixed>> $messages @return array<string,mixed> */
    private function continueDraft(array $draft, string $intent, array $messages): array
    {
        if (! in_array($intent, ['ORDER_CONTINUE', 'ORDER_CONFIRMATION'], true)) {
            return $draft;
        }

        $latest = collect($messages)
            ->reverse()
            ->first(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text');
        $text = trim((string) data_get($latest, 'body', ''));
        if ($text === '') {
            return $draft;
        }

        $normalized = Str::of($text)->ascii()->lower()->squish()->toString();
        $fulfillment = $draft['fulfillment'] ?? null;
        $payment = $draft['payment_method'] ?? null;
        $address = $draft['address'] ?? null;
        if (preg_match('/\bpix\b/', $normalized) === 1) {
            $payment = 'pix';
        }
        if (preg_match('/\b(rua|avenida|av\.?|travessa)\b/', $normalized) === 1) {
            $fulfillment = 'delivery';
            if (preg_match('/\b(?:rua|avenida|av\.?|travessa)\s+[^\n,]+(?:,?\s*\d+)?/iu', $text, $match) === 1) {
                $address = trim($match[0]);
            }
        }

        return [...$draft, 'fulfillment' => $fulfillment, 'payment_method' => $payment, 'address' => $address];
    }

    /** @param array<string,mixed> $draft @param array<string,mixed> $context @param array<string,mixed> $metadata @return array<string,mixed> */
    /** @param array<string,mixed> $draft @param array<string,mixed> $context @return array<string,mixed> */
    private function mergeCandidateItem(array $draft, array $context): array
    {
        $items = array_values((array) ($draft['items'] ?? []));
        $pendingClarificationCandidate = data_get($context, 'pending_clarification.candidate');
        $candidate = is_array($pendingClarificationCandidate)
            && (int) ($pendingClarificationCandidate['product_id'] ?? 0) > 0
                ? $pendingClarificationCandidate
                : data_get($context, 'pending_order_state.candidate_items.0');
        if (! is_array($candidate)) {
            return $draft;
        }
        if ($items === []) {
            if ((int) ($candidate['product_id'] ?? 0) < 1) {
                return $draft;
            }
            $items[] = [
                'menu_item_id' => (int) $candidate['product_id'],
                'menu_item_slug' => (string) ($candidate['product_slug'] ?? ''),
                'quantity' => max(1, (int) ($candidate['quantity'] ?? 1)),
                'selections' => [],
                'daily_component_ids' => [],
                'removed_components' => [],
                'item_notes' => '',
            ];
        }

        $item = $items[0];
        if (! is_array($item)) {
            return $draft;
        }
        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];
        $meats = collect([
            ...(array) ($candidate['meats'] ?? []),
            ...(array) ($selections['meats'] ?? []),
        ])->filter(fn (mixed $meat): bool => is_string($meat) && trim($meat) !== '')
            ->unique(fn (string $meat): string => $this->key($meat))
            ->values()
            ->all();
        $dailyComponentIds = collect([
            ...(array) ($candidate['daily_component_ids'] ?? []),
            ...(array) ($item['daily_component_ids'] ?? []),
        ])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values()->all();

        $items[0] = [
            ...$item,
            'quantity' => max(1, (int) ($item['quantity'] ?? $candidate['quantity'] ?? 1)),
            'selections' => [...$selections, 'meat' => null, 'meats' => $meats],
            'daily_component_ids' => $dailyComponentIds,
            'item_notes' => trim((string) ($item['item_notes'] ?? '')) !== ''
                ? (string) $item['item_notes']
                : (string) ($candidate['notes'] ?? ''),
            'candidate_origin' => [
                'family' => $candidate['family'] ?? null,
                'provenance' => (string) ($candidate['provenance'] ?? 'customer_explicit'),
            ],
        ];

        return [...$draft, 'items' => $items];
    }

    private function mergePendingDraft(array $draft, array $context, string $intent, array $metadata): array
    {
        if (! in_array($intent, ['ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)
            || is_array(data_get($context, 'active_order'))
            || data_get($metadata, 'semantic_pending_order_correction') === true
            || data_get($metadata, 'semantic_read_only') === true) {
            return $draft;
        }

        $pending = data_get($context, 'pending_order_state.draft_order');
        if (! is_array($pending) || (array) ($pending['items'] ?? []) === []) {
            return $draft;
        }

        $merged = $pending;
        foreach (['fulfillment', 'address', 'payment_method'] as $field) {
            $value = $draft[$field] ?? null;
            if ($value !== null && $value !== '') {
                $merged[$field] = $value;
            }
        }

        $items = array_values((array) ($pending['items'] ?? []));
        foreach ((array) ($draft['items'] ?? []) as $proposed) {
            if (! is_array($proposed)) {
                continue;
            }
            $index = collect($items)->search(function (mixed $existing) use ($proposed): bool {
                if (! is_array($existing)) {
                    return false;
                }
                $proposedId = (int) ($proposed['menu_item_id'] ?? 0);
                $existingId = (int) ($existing['menu_item_id'] ?? 0);
                if ($proposedId > 0 && $existingId > 0) {
                    return $proposedId === $existingId;
                }

                return filled($proposed['menu_item_slug'] ?? null)
                    && (string) ($proposed['menu_item_slug'] ?? '') === (string) ($existing['menu_item_slug'] ?? '');
            });
            if ($index === false) {
                $items[] = $proposed;

                continue;
            }

            $existing = $items[$index];
            $replaceSelections = $intent === 'ORDER_CHANGE';
            $selections = (array) ($existing['selections'] ?? []);
            $acceptProposedSelections = data_get($metadata, 'semantic_delta_validated') === true;
            foreach ((array) ($proposed['selections'] ?? []) as $key => $value) {
                $current = $selections[$key] ?? null;
                $selections[$key] = ! $replaceSelections && is_array($current) && is_array($value)
                    ? array_values(array_unique([...$current, ...$value], SORT_REGULAR))
                    : $value;
            }
            $items[$index] = [
                ...$existing,
                ...$proposed,
                'selections' => $selections,
                'daily_component_ids' => array_values(array_unique([
                    ...(array) ($existing['daily_component_ids'] ?? []),
                    ...($acceptProposedSelections ? (array) ($proposed['daily_component_ids'] ?? []) : []),
                ])),
                'daily_component_candidates' => $acceptProposedSelections
                    ? (array) ($proposed['daily_component_candidates'] ?? $existing['daily_component_candidates'] ?? [])
                    : (array) ($existing['daily_component_candidates'] ?? []),
                'validated_order_options' => $acceptProposedSelections
                    ? (array) ($proposed['validated_order_options'] ?? $existing['validated_order_options'] ?? [])
                    : (array) ($existing['validated_order_options'] ?? []),
                'removed_components' => array_values(array_unique([
                    ...(array) ($existing['removed_components'] ?? []),
                    ...(array) ($proposed['removed_components'] ?? []),
                ])),
                'item_notes' => filled($proposed['item_notes'] ?? null)
                    ? (string) $proposed['item_notes']
                    : (string) ($existing['item_notes'] ?? ''),
            ];
        }

        return [
            ...$merged,
            ...$draft,
            'items' => $items,
            'fulfillment' => $merged['fulfillment'] ?? null,
            'address' => $merged['address'] ?? '',
            'payment_method' => $merged['payment_method'] ?? '',
        ];
    }

    /** @return array{code:string,message:string}|null */
    private function priceWarning(Product $product, string $text): ?array
    {
        $pattern = match ($product->menu_rule_code) {
            'n8_tradicional' => '/\bn8(?:\s*(?:livre|tradicional))?\b.{0,24}?\b(\d{1,3})[,.](\d{2})\b/i',
            'n9_tradicional' => '/\bn9(?:\s*(?:livre|tradicional))?\b.{0,24}?\b(\d{1,3})[,.](\d{2})\b/i',
            default => null,
        };
        if ($pattern === null || preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $informed = ((int) $matches[1] * 100) + (int) $matches[2];
        if ($informed === (int) $product->base_price_cents) {
            return null;
        }

        return ['code' => 'PRICE_MISMATCH', 'message' => 'O preço informado pelo cliente diverge do preço atual do cardápio.'];
    }
}
