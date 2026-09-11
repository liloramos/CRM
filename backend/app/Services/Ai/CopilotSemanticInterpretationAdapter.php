<?php

namespace App\Services\Ai;

use App\Data\Ai\CopilotAnalysis;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Converts a model interpretation into a backend-owned candidate delta.
 * Every applied value/path comes from the conversation frame or canonical catalog.
 */
final class CopilotSemanticInterpretationAdapter
{
    public function __construct(private readonly CopilotMenuAliasResolver $aliases) {}

    /** @param array<string,mixed> $context */
    public function adapt(Company $company, CopilotAnalysis $analysis, array $context): CopilotAnalysis
    {
        $interpretation = data_get($analysis->metadata, 'semantic_interpretation');
        if (! is_array($interpretation) || $interpretation === []) {
            return $analysis;
        }

        $intents = array_values((array) ($interpretation['intents'] ?? []));
        $metadata = [...$analysis->metadata, 'semantic_recovery' => true];
        if (($interpretation['mutates_order'] ?? false) !== true
            && array_intersect($intents, ['compare_products', 'ask_product_information', 'ask_pending_slot_options']) !== []) {
            return $this->copy(
                $analysis,
                draftOrder: ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                missingInformation: [],
                metadata: [...$metadata, 'semantic_read_only' => true],
            );
        }

        $current = $analysis;
        if (in_array('correct_order', $intents, true)) {
            $current = $this->pendingOrderCorrection($company, $current, $context, $interpretation, $metadata);
        }

        $workingContext = $context;
        if (data_get($current->metadata, 'semantic_delta_validated') === true) {
            data_set($workingContext, 'pending_order_state.draft_order', $current->draftOrder);
        }

        if (in_array('answer_pending_slot', $intents, true)
            && data_get($workingContext, 'conversation_frame.last_assistant_goal.type') === 'provide_value') {
            $pending = $this->pendingValueDelta($current, $workingContext, $interpretation, $current->metadata);

            return data_get($pending->metadata, 'semantic_delta_validated') === true ? $pending : $current;
        }

        if (array_intersect($intents, ['answer_pending_slot', 'select_option']) !== []) {
            $pending = $this->pendingSlotDelta($current, $workingContext, $interpretation, $current->metadata);

            return data_get($pending->metadata, 'semantic_delta_validated') === true
                || data_get($pending->metadata, 'semantic_pending_slot_rejected') === true
                    ? $pending
                    : $current;
        }

        return $current === $analysis ? $this->copy($analysis, metadata: $metadata) : $current;
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $interpretation @param array<string,mixed> $metadata */
    private function pendingValueDelta(CopilotAnalysis $analysis, array $context, array $interpretation, array $metadata): CopilotAnalysis
    {
        $goal = data_get($context, 'conversation_frame.last_assistant_goal');
        $pendingDraft = data_get($context, 'pending_order_state.draft_order');
        $goalSlot = $this->slot((string) data_get($goal, 'slot', ''));
        $subject = $this->slot((string) ($interpretation['subject'] ?? ''));
        if (! is_array($pendingDraft) || $goalSlot !== 'address' || ($subject !== '' && $subject !== $goalSlot)) {
            return $this->rejected($analysis, $metadata, 'O valor informado não corresponde ao campo pendente do pedido.');
        }

        $address = trim((string) data_get($analysis->draftOrder, 'address', data_get($interpretation, 'order_delta.address', '')));
        if ($address === '') {
            return $this->rejected($analysis, $metadata, 'O endereço informado não pôde ser validado.');
        }

        return $this->copy(
            $analysis,
            intent: 'ORDER_CONTINUE',
            draftOrder: [...$pendingDraft, 'address' => $address, 'fulfillment' => 'delivery'],
            metadata: [...$metadata, 'semantic_delta_validated' => true, 'semantic_slot' => 'address'],
        );
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $interpretation @param array<string,mixed> $metadata */
    private function pendingSlotDelta(CopilotAnalysis $analysis, array $context, array $interpretation, array $metadata): CopilotAnalysis
    {
        $goal = data_get($context, 'conversation_frame.last_assistant_goal');
        $pendingDraft = data_get($context, 'pending_order_state.draft_order');
        if (! is_array($goal) || ! is_array($pendingDraft) || ($goal['type'] ?? null) !== 'choose_option') {
            return $this->rejected($analysis, $metadata, 'Nenhuma escolha pendente pôde ser validada neste turno.');
        }

        $goalSlot = $this->slot((string) ($goal['slot'] ?? ''));
        $subject = $this->slot((string) ($interpretation['subject'] ?? ''));
        if ($goalSlot === '' || ($subject !== '' && $subject !== $goalSlot)) {
            return $this->rejected($analysis, $metadata, 'A resposta não corresponde ao campo pendente do pedido.');
        }

        $allowed = array_values(array_filter(
            (array) ($goal['allowed_values'] ?? []),
            fn (mixed $option): bool => is_array($option) && is_array($option['state_delta'] ?? null),
        ));
        $selected = $this->selectedOptions($allowed, $interpretation);
        if ($selected === []) {
            $missing = collect((array) data_get($context, 'pending_order_state.missing_fields', []))
                ->map(fn (mixed $code): array => ['code' => strtoupper((string) $code), 'label' => ucfirst($goalSlot)])
                ->values()
                ->all();

            return $this->copy(
                $analysis,
                intent: 'ORDER_CONTINUE',
                draftOrder: $pendingDraft,
                missingInformation: $missing,
                warnings: [...$analysis->warnings, ['code' => 'DOMAIN_SELECTION_REJECTED', 'message' => 'A opção informada não está entre as opções válidas para este pedido.']],
                metadata: [...$metadata, 'semantic_delta_validated' => false, 'semantic_pending_slot_rejected' => true],
            );
        }

        $draft = $pendingDraft;
        $appliedIds = [];
        foreach ($selected as $option) {
            $appliedIds[] = (string) ($option['id'] ?? '');
            foreach ((array) $option['state_delta'] as $path => $value) {
                if (! is_string($path) || $path === '') {
                    continue;
                }
                $existing = data_get($draft, $path);
                if (is_array($existing) && is_array($value)) {
                    $value = array_values(array_unique([...$existing, ...$value]));
                }
                data_set($draft, $path, $value);
            }
        }

        return $this->copy(
            $analysis,
            intent: 'ORDER_CONTINUE',
            draftOrder: $draft,
            metadata: [
                ...$metadata,
                'semantic_delta_validated' => true,
                'semantic_slot' => $goalSlot,
                'semantic_allowed_value_ids' => array_values(array_filter($appliedIds)),
                ...($goalSlot === 'product' || $goalSlot === 'product_variant'
                    ? ['semantic_grounded_product_ids' => array_values(array_filter(array_map('intval', $appliedIds)))]
                    : []),
            ],
        );
    }

    /** @param list<array<string,mixed>> $allowed @param array<string,mixed> $interpretation @return list<array<string,mixed>> */
    private function selectedOptions(array $allowed, array $interpretation): array
    {
        $index = filter_var($interpretation['selected_option_index'] ?? null, FILTER_VALIDATE_INT);
        if ($index !== false && $index > 0 && isset($allowed[$index - 1])) {
            return [$allowed[$index - 1]];
        }

        $targets = collect((array) ($interpretation['target_products'] ?? []))
            ->map(fn (mixed $target): string => $this->key((string) $target))
            ->filter()
            ->unique();
        if ($targets->isNotEmpty()) {
            $targetMatches = collect($allowed)->filter(fn (array $option): bool => $this->optionKeys($option, true)->intersect($targets)->isNotEmpty());
            if ($targetMatches->count() !== 1) {
                return [];
            }

            return [$targetMatches->first()];
        }

        $candidates = collect([
            (string) ($interpretation['candidate_value'] ?? ''),
            ...(array) ($interpretation['candidate_values'] ?? []),
        ])->map(fn (mixed $candidate): string => $this->key((string) $candidate))->filter()->unique();
        if ($candidates->isEmpty()) {
            return [];
        }

        $selected = collect();
        foreach ($candidates as $candidate) {
            $exact = collect($allowed)->filter(fn (array $option): bool => $this->optionKeys($option, true)->contains($candidate));
            if ($exact->count() > 1) {
                return [];
            }
            if ($exact->count() === 1) {
                $selected->push($exact->first());

                continue;
            }
            if (strlen($candidate) < 3) {
                return [];
            }

            $fragment = collect($allowed)->filter(fn (array $option): bool => $this->optionKeys($option, false)
                ->contains(fn (string $key): bool => str_contains($key, $candidate)));
            if ($fragment->count() !== 1) {
                return [];
            }
            $selected->push($fragment->first());
        }

        return $selected
            ->unique(fn (array $option): string => (string) ($option['id'] ?? '').'|'.(string) ($option['label'] ?? ''))
            ->values()
            ->all();
    }

    /** @return Collection<int,string> */
    private function optionKeys(array $option, bool $includeId)
    {
        return collect([
            ...($includeId ? [(string) ($option['id'] ?? '')] : []),
            (string) ($option['label'] ?? ''),
            ...(array) ($option['aliases'] ?? []),
        ])->map(fn (mixed $value): string => $this->key((string) $value))->filter()->unique();
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $interpretation @param array<string,mixed> $metadata */
    private function pendingOrderCorrection(Company $company, CopilotAnalysis $analysis, array $context, array $interpretation, array $metadata): CopilotAnalysis
    {
        $pending = data_get($context, 'pending_order_state');
        $draft = data_get($pending, 'draft_order');
        if (! is_array($pending) || ! is_array($draft) || is_array(data_get($context, 'active_order'))) {
            return $this->copy($analysis, metadata: [...$metadata, 'semantic_delta_validated' => false]);
        }

        $latest = (string) data_get($context, 'latest_message.body', '');
        $items = array_values((array) ($draft['items'] ?? []));
        $validated = false;
        $rejectionReason = 'unsupported_operation';
        $groundedProductIds = [];
        $validatedOperations = [];
        foreach ((array) ($interpretation['state_operations'] ?? []) as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            if ($this->slot((string) ($operation['subject'] ?? '')) === 'carne') {
                $meatCorrection = $this->applyMeatCorrection($items, $operation, $context, $latest);
                $items = $meatCorrection['items'];
                if ($meatCorrection['validated']) {
                    $validated = true;
                    $groundedProductIds = [...$groundedProductIds, ...$meatCorrection['product_ids']];
                    $validatedOperations[] = $this->operationTrace($operation, 'carne', $meatCorrection['component_ids']);
                } else {
                    $rejectionReason = $meatCorrection['reason'];
                }

                continue;
            }

            if ($this->slot((string) ($operation['subject'] ?? '')) === 'fulfillment'
                && in_array((string) ($operation['operation'] ?? ''), ['set', 'replace'], true)) {
                $fulfillment = $this->canonicalFulfillment((string) ($operation['to'] ?? $operation['value'] ?? ''));
                if ($fulfillment !== null && $this->fulfillmentIsGrounded($latest, $fulfillment)) {
                    $draft['fulfillment'] = $fulfillment;
                    $validated = true;
                    $validatedOperations[] = $this->operationTrace($operation, 'fulfillment', [$fulfillment]);
                } else {
                    $rejectionReason = 'fulfillment_not_grounded';
                }

                continue;
            }

            if (($operation['operation'] ?? null) !== 'replace'
                || $this->slot((string) ($operation['subject'] ?? '')) !== 'product') {
                continue;
            }

            $from = $this->productInDraft($company, $items, (string) ($operation['from'] ?? ''));
            $to = $this->aliases->resolve($company, null, (string) ($operation['to'] ?? ''));
            if (! $from instanceof Product || ! $to instanceof Product) {
                $rejectionReason = 'canonical_product_not_found';

                continue;
            }
            if (! $this->mentioned($from, $latest) || ! $this->mentioned($to, $latest)) {
                $rejectionReason = 'product_not_grounded_in_latest_message';

                continue;
            }

            $removed = collect($items)->filter(fn (mixed $item): bool => is_array($item)
                && (int) ($item['menu_item_id'] ?? 0) === (int) $from->id);
            if ($removed->isEmpty()) {
                $rejectionReason = 'source_product_not_in_pending_order';

                continue;
            }

            $quantity = max(1, (int) $removed->sum(fn (array $item): int => max(1, (int) ($item['quantity'] ?? 1))));
            $items = collect($items)
                ->reject(fn (mixed $item): bool => is_array($item)
                    && (int) ($item['menu_item_id'] ?? 0) === (int) $from->id)
                ->values()
                ->all();
            $targetIndex = collect($items)->search(fn (mixed $item): bool => is_array($item)
                && (int) ($item['menu_item_id'] ?? 0) === (int) $to->id);
            if ($targetIndex === false) {
                $items[] = [
                    'menu_item_id' => (int) $to->id,
                    'menu_item_slug' => (string) $to->slug,
                    'quantity' => $quantity,
                    'selections' => [],
                    'removed_components' => [],
                    'item_notes' => '',
                ];
            } else {
                $items[$targetIndex]['quantity'] = max(1, (int) ($items[$targetIndex]['quantity'] ?? 1)) + $quantity;
            }
            $validated = true;
            $groundedProductIds[] = (int) $to->id;
            $validatedOperations[] = $this->operationTrace($operation, 'product', [(int) $to->id]);
        }

        if (! $validated) {
            return $this->rejected($analysis, [...$metadata, 'semantic_delta_rejection_reason' => $rejectionReason], 'A correção proposta não pôde ser validada no pedido pendente.');
        }

        return $this->copy(
            $analysis,
            intent: 'ORDER_CONTINUE',
            draftOrder: [...$draft, 'items' => $items],
            metadata: [
                ...$metadata,
                'semantic_delta_validated' => true,
                'semantic_pending_order_correction' => true,
                'semantic_grounded_product_ids' => array_values(array_unique($groundedProductIds)),
                'validated_semantic_operations' => $validatedOperations,
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,mixed>  $operation
     * @param  array<string,mixed>  $context
     * @return array{items:list<array<string,mixed>>,validated:bool,product_ids:list<int>,component_ids:list<int|string>,reason:string}
     */
    private function applyMeatCorrection(array $items, array $operation, array $context, string $latest): array
    {
        $verb = (string) ($operation['operation'] ?? '');
        $from = $this->canonicalMeat($context, (string) ($operation['from'] ?? ''));
        $to = $this->canonicalMeat($context, (string) ($operation['to'] ?? ''));
        if (! in_array($verb, ['add', 'remove', 'replace', 'set'], true)
            || (in_array($verb, ['remove', 'replace'], true) && ($from === null || ! $this->textMentions($latest, $from)))
            || (in_array($verb, ['add', 'replace', 'set'], true) && ($to === null || ! $this->textMentions($latest, $to)))) {
            return compact('items') + ['validated' => false, 'product_ids' => [], 'component_ids' => [], 'reason' => 'canonical_meat_not_grounded'];
        }

        foreach ($items as $index => $item) {
            $meats = collect((array) data_get($item, 'selections.meats', []))
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->values();
            $fromIndex = $from === null
                ? false
                : $meats->search(fn (string $value): bool => $this->key($value) === $this->key($from));
            if (in_array($verb, ['remove', 'replace'], true) && $fromIndex === false) {
                continue;
            }

            if ($verb === 'remove') {
                $meats->forget($fromIndex);
            } elseif ($verb === 'replace') {
                $meats->put($fromIndex, $to);
            } elseif ($verb === 'set') {
                $meats = collect([$to]);
            } else {
                $meats->push($to);
            }

            data_set($items[$index], 'selections.meats', $meats->filter()->unique(fn (string $value): string => $this->key($value))->values()->all());
            data_set($items[$index], 'selections.meat', null);

            return [
                'items' => array_values($items),
                'validated' => true,
                'product_ids' => [(int) ($item['menu_item_id'] ?? 0)],
                'component_ids' => array_values(array_filter([$from, $to])),
                'reason' => '',
            ];
        }

        return compact('items') + ['validated' => false, 'product_ids' => [], 'component_ids' => [], 'reason' => 'source_meat_not_in_pending_order'];
    }

    /** @return array<string,mixed> */
    private function operationTrace(array $operation, string $target, array $canonicalIds): array
    {
        return [
            'type' => (string) ($operation['operation'] ?? ''),
            'target' => $target,
            'value' => $operation['to'] ?? $operation['value'] ?? null,
            'canonical_ids' => array_values($canonicalIds),
            'provenance' => 'semantic_interpretation_customer_message',
            'confidence' => $operation['confidence'] ?? null,
            'state_delta_validated' => true,
        ];
    }

    private function canonicalFulfillment(string $value): ?string
    {
        return match ($this->key($value)) {
            'delivery', 'entrega', 'entregar' => 'delivery',
            'pickup', 'retirada', 'retirar', 'buscar' => 'pickup',
            default => null,
        };
    }

    private function fulfillmentIsGrounded(string $text, string $fulfillment): bool
    {
        $normalized = $this->key($text);
        $terms = $fulfillment === 'delivery' ? ['entrega', 'entregar', 'delivery'] : ['retirada', 'retirar', 'buscar', 'pickup'];

        return collect($terms)->contains(fn (string $term): bool => preg_match('/\b'.preg_quote($term, '/').'\b/', $normalized) === 1);
    }

    /** @param array<string,mixed> $context */
    private function canonicalMeat(array $context, string $candidate): ?string
    {
        $needle = $this->key($candidate);
        if ($needle === '') {
            return null;
        }

        $matches = collect((array) data_get($context, 'daily_meats', []))
            ->filter(fn (mixed $meat): bool => is_array($meat))
            ->filter(function (array $meat) use ($needle): bool {
                return collect([(string) ($meat['name'] ?? ''), (string) ($meat['slug'] ?? ''), ...(array) ($meat['aliases'] ?? [])])
                    ->contains(fn (string $value): bool => $this->key($value) === $needle);
            });

        return $matches->count() === 1 ? (string) ($matches->first()['name'] ?? '') : null;
    }

    private function textMentions(string $text, string $value): bool
    {
        return str_contains($this->key($text), $this->key($value));
    }

    private function mentioned(Product $product, string $text): bool
    {
        $haystack = $this->key($text);
        $tokens = collect(preg_split('/[^a-z0-9]+/', strtolower(Str::ascii((string) $product->name))) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 4);

        return $tokens->contains(fn (string $token): bool => str_contains($haystack, $token));
    }

    /** @param list<array<string,mixed>> $items */
    private function productInDraft(Company $company, array $items, string $identifier): ?Product
    {
        $needle = Str::of($identifier)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();

        foreach ($items as $item) {
            if (! is_array($item) || (int) ($item['menu_item_id'] ?? 0) < 1) {
                continue;
            }
            $product = $this->aliases->resolve($company, (int) $item['menu_item_id'], '');
            if (! $product instanceof Product) {
                continue;
            }
            $identities = collect([(string) $product->id, (string) $product->slug, (string) $product->name])
                ->map(fn (string $value): string => Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString());
            if ($identities->contains($needle)) {
                return $product;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $metadata */
    private function rejected(CopilotAnalysis $analysis, array $metadata, string $message): CopilotAnalysis
    {
        return $this->copy(
            $analysis,
            warnings: [...$analysis->warnings, ['code' => 'SEMANTIC_DELTA_REJECTED', 'message' => $message]],
            metadata: [...$metadata, 'semantic_delta_validated' => false],
        );
    }

    private function slot(string $value): string
    {
        return match (Str::of($value)->ascii()->lower()->snake()->toString()) {
            'salad', 'salads', 'salada_casa' => 'salada',
            'meat', 'meats' => 'carne',
            'payment', 'pagamento' => 'payment_method',
            default => Str::of($value)->ascii()->lower()->snake()->toString(),
        };
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    /**
     * @param  array<string,mixed>|null  $draftOrder
     * @param  list<array<string,mixed>>|null  $missingInformation
     * @param  list<array<string,mixed>>|null  $warnings
     * @param  array<string,mixed>|null  $metadata
     */
    private function copy(
        CopilotAnalysis $analysis,
        ?string $intent = null,
        ?array $draftOrder = null,
        ?array $missingInformation = null,
        ?array $warnings = null,
        ?array $metadata = null,
    ): CopilotAnalysis {
        return new CopilotAnalysis(
            $intent ?? $analysis->intent,
            $analysis->confidence,
            $analysis->summary,
            $draftOrder ?? $analysis->draftOrder,
            $missingInformation ?? $analysis->missingInformation,
            $warnings ?? $analysis->warnings,
            $analysis->suggestedReply,
            true,
            $metadata ?? $analysis->metadata,
            $analysis->replyMessages,
        );
    }
}
