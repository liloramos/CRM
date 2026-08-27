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
            );
        }
        $selectionMessages = $this->selectionMessages($context, $latestIntent);
        $applyNewOrderHistoryBoundary = $latestIntent === 'ORDER_CREATE' && data_get($context, 'active_order') === null;
        $historicalMessages = $applyNewOrderHistoryBoundary
            ? $this->historicalInboundMessages(data_get($context, 'messages', []))
            : [];
        $warnings = $analysis->warnings;
        $invalidQuantityDiscarded = false;
        $ungroundedProductDiscarded = false;
        $pendingClarification = data_get($context, 'pending_clarification');
        $resolvedPendingClarification = $this->resolvedPendingClarification($pendingClarification);
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
            ? ($analysis->draftOrder['items'] ?? [])
            : [$this->resolvedPendingItem($pendingClarification)];
        $discardedN8Indexes = [];
        $validateItem = function (array $item, int $index) use ($company, $date, $selectionMessages, $applyNewOrderHistoryBoundary, $historicalMessages, $resolvedPendingClarification, &$warnings, &$invalidQuantityDiscarded, &$ungroundedProductDiscarded, &$discardedN8Indexes): ?array {
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
            if (! $product || ! $product->is_active || ! $product->is_available_by_default) {
                $warnings[] = ['code' => 'UNRESOLVED_MENU_ITEM', 'message' => 'Nao foi possivel associar o item a um produto disponivel.', 'item_index' => $index];

                return [...$item, 'valid' => false];
            }
            if ($applyNewOrderHistoryBoundary) {
                $item = $this->withoutHistoricalOnlyFreeAssemblySaladOptOut($product, $item, $selectionMessages, $historicalMessages);
            }
            if (! $this->products->isGrounded($product, $selectionMessages)) {
                $warnings[] = ['code' => 'UNGROUNDED_PRODUCT', 'message' => 'O produto sugerido nao possui evidencia suficiente na mensagem do cliente.', 'item_index' => $index];
                $ungroundedProductDiscarded = true;
                if ($product->menu_rule_code === 'n8_casa') {
                    $discardedN8Indexes[] = $index;
                }

                return null;
            }
            $item = $this->products->enrichN8Traditional($product, $item, $selectionMessages);
            if ($resolvedPendingClarification === null) {
                $item = $this->selections->recoverExplicitDailyMeats($company, $product, $date, $item, $selectionMessages);
            }
            $dailyComponents = $this->selections->recoverExplicitDailyComponents($company, $product, $date, $item, $selectionMessages);
            $item = $dailyComponents['item'];
            $warnings = [...$warnings, ...$dailyComponents['warnings']];
            $item = $this->selections->recoverExplicitFreeAssemblySaladOptOut($company, $product, $date, $item, $selectionMessages);
            if ($this->hasHouseSaladDelegation($selectionMessages) && trim((string) ($item['item_notes'] ?? '')) === '') {
                $item['item_notes'] = 'Salada à escolha da casa';
            }
            if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)
                && $this->hasExplicitWithoutMeat($this->products->selectionText($product, $selectionMessages))) {
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

            $priceWarning = $this->priceWarning($product, $this->products->selectionText($product, $selectionMessages));
            if ($priceWarning !== null) {
                $warnings[] = [...$priceWarning, 'item_index' => $index];
            }

            return $result['item'];
        };
        $items = collect($proposedItems)->map($validateItem)->filter()->values()->all();

        // Recover each explicit product reference independently. A rejected N8 Casa must not erase an
        // explicit N8 Livre just because another item in the same message survived validation.
        $recoveredItems = $this->products->recoverExplicitItems($company, $selectionMessages, $date);
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
        ], $items);
        $draftOrder = $this->continueDraft($analysis->draftOrder, $latestIntent, $selectionMessages);
        if (($draftOrder['fulfillment'] ?? null) === 'delivery' && blank($draftOrder['address'] ?? null)) {
            $missing[] = ['code' => 'ADDRESS', 'label' => 'Endereco'];
        }
        if ($this->hasHouseSaladDelegation($selectionMessages)) {
            $missing = array_values(array_filter($missing, fn (array $entry): bool => strtoupper((string) ($entry['code'] ?? '')) !== 'SALADA'));
        }
        $missing = $this->intents->refineMissing($missing, $items, $context);

        return new CopilotAnalysis(
            $this->intents->finalize($analysis->intent, $items, $missing, $this->dedupeWarnings($warnings), $context),
            $analysis->confidence,
            $analysis->summary,
            [...$draftOrder, 'items' => $items],
            $this->identity->missing($missing, $this->hasResolvedN8Variant($items)),
            $this->dedupeWarnings($warnings),
            $analysis->suggestedReply,
            true,
            [...$analysis->metadata, 'validation_warning_count' => count($warnings)],
        );
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
        $allowed = ['PRODUCT', 'MENU_ITEM', 'PREVIOUS_ORDER_REFERENCE', 'VALID_QUANTITY', 'CARNE', 'SALADA', 'ADDRESS', 'N8_VARIANT', 'EXTRA_BEEF_QUANTITY'];

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
        if (in_array($intent, ['ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)) {
            return $this->currentPendingOrderMessages($messages);
        }

        foreach (array_reverse($messages) as $message) {
            if (($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text') {
                return [$message];
            }
        }

        return [];
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

        return [
            'menu_item_id' => (int) data_get($pending, 'candidate.product_id'),
            'menu_item_slug' => (string) data_get($pending, 'candidate.product_slug'),
            'quantity' => max(1, (int) data_get($pending, 'candidate.quantity', 1)),
            'selections' => [
                'meat_mode' => 'traditional',
                'meat' => null,
                'meats' => [(string) data_get($option, 'display_name')],
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
