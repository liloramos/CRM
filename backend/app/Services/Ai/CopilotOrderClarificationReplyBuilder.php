<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Builds read-only, backend-grounded continuations for informal order turns.
 * It never creates an item: product rules are validated only after the customer
 * chooses an actual menu product.
 */
final class CopilotOrderClarificationReplyBuilder
{
    public function __construct(
        private readonly CopilotProductDecisionFacts $decisionFacts,
        private readonly CopilotCanonicalEntityResolver $entities,
    ) {}

    /**
     * Renders the reducer-owned objective without deriving or mutating state.
     * Informational turns keep their grounded answer and receive the pending CTA.
     *
     * @param  array<string,mixed>  $safe
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function continueFromObjective(array $safe, array $state, array $context): array
    {
        $objective = strtoupper((string) ($state['next_objective'] ?? ''));
        $prompt = match ($objective) {
            'ASK_PRODUCT' => $this->productPrompt($safe, $state, $context),
            'ASK_MEAT' => $this->optionPrompt($state, $context, 'carne', 'Qual carne você prefere?'),
            'ASK_SALAD' => $this->optionPrompt($state, $context, 'salada', 'Qual salada você deseja?'),
            'ASK_COMPONENTS' => $this->componentsPrompt($state),
            'ASK_QUANTITY' => 'Quantas unidades você deseja?',
            'ASK_FLAVOR' => 'Qual sabor você prefere?',
            'CONFIRM_ITEM' => $this->itemConfirmation($state, $context),
            'ASK_MORE_ITEMS' => 'Item confirmado ✅ Quer acrescentar mais alguma coisa, como uma bebida?',
            'ASK_FULFILLMENT' => 'Vai ser para retirada ou entrega?',
            'ASK_LOCATION' => 'Qual é o endereço da entrega? Se preferir, pode compartilhar sua localização.',
            'ASK_PAYMENT_METHOD' => $this->paymentPrompt($context),
            'WAIT_PAYMENT_PROOF' => 'Depois do pagamento, envie o comprovante aqui para nossa equipe conferir.',
            default => '',
        };
        $existingSource = (string) data_get($safe, 'metadata.reply_source', '');
        $readOnly = data_get($safe, 'metadata.semantic_read_only') === true
            || collect((array) data_get($safe, 'metadata.semantic_interpretation.intents', []))
                ->intersect(['ask_product_information', 'compare_products', 'ask_pending_slot_options'])
                ->isNotEmpty()
            || (in_array((string) ($safe['intent'] ?? ''), [
                'MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST',
                'PAYMENT_QUESTION', 'DELIVERY_QUESTION', 'ORDER_STATUS', 'GENERAL_QUESTION',
            ], true) && in_array($existingSource, [
                'daily_menu', 'product_catalog', 'operating_hours', 'operating_hours_unconfigured',
                'customer_facing_policy', 'semantic_grounded_product_information',
                'semantic_grounded_comparison', 'semantic_grounded_options',
            ], true));
        $existingMessages = collect((array) ($safe['reply_messages'] ?? []))
            ->filter(fn (mixed $message): bool => is_string($message) && trim($message) !== '')
            ->map(fn (string $message): string => trim($message));
        $existingReply = trim((string) ($safe['suggested_reply'] ?? ''));
        if ($existingMessages->isEmpty() && $existingReply !== '') {
            $existingMessages->push($existingReply);
        }
        $structuredProductClarification = $existingSource === 'order_clarification'
            && in_array((string) data_get($safe, 'metadata.clarification_kind', ''), ['product_variant', 'product_selection'], true);
        if ($structuredProductClarification && $prompt !== '' && $existingMessages->isNotEmpty()) {
            // The last legacy part is its old question; keep only the factual
            // acknowledgement/notices and let the reducer-owned prompt replace it.
            $existingMessages = $existingMessages->slice(0, -1)->values();
        }
        $informationTurn = $readOnly
            || $structuredProductClarification
            || in_array($existingSource, [
                'explicit_handoff', 'payment_selection', 'daily_menu', 'product_catalog',
                'operating_hours', 'operating_hours_unconfigured', 'customer_facing_policy',
                'semantic_grounded_product_information', 'semantic_grounded_comparison', 'semantic_grounded_options',
            ], true);
        $informationResponse = $informationTurn
            ? $existingMessages
                ->reject(fn (string $message): bool => $prompt !== '' && (
                    $this->key($message) === $this->key($prompt)
                    || str_contains($this->key($message), $this->key($prompt))
                ))
                ->unique()
                ->implode("\n\n")
            : '';
        if ($prompt === '') {
            $messages = $existingMessages
                ->unique(fn (string $part): string => $this->key($part))
                ->take(3)
                ->values()
                ->all();
            $singleMessageReply = $existingSource === 'customer_facing_policy'
                || data_get($safe, 'metadata.clarification_kind') === 'product_compatibility';
            if ($singleMessageReply && $messages !== []) {
                $messages = [implode("\n\n", $messages)];
            }
            $informationResponse = implode("\n\n", $messages);
        } else {
            $message = collect([$informationResponse, $prompt])
                ->filter(fn (string $part): bool => trim($part) !== '')
                ->unique(fn (string $part): string => $this->key($part))
                ->implode("\n\n");
            $messages = $message === '' ? [] : [$message];
        }

        return [
            ...$safe,
            'suggested_reply' => implode("\n\n", $messages),
            'reply_messages' => $messages,
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => ($informationTurn || $prompt === '') && $existingSource !== '' ? $existingSource : 'turn_loop_objective',
                'reply_composed_by' => 'backend',
                'final_reply_composer' => 'post_reducer_turn_loop',
                'information_response' => $informationResponse !== '' ? $informationResponse : null,
                'final_reply_messages' => $messages,
            ],
        ];
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $state @param array<string,mixed> $context */
    private function productPrompt(array $safe, array $state, array $context): string
    {
        if (data_get($safe, 'metadata.reply_source') === 'order_clarification'
            || data_get($safe, 'metadata.reply_source') === 'resolved_turn_product_discovery') {
            return '';
        }

        $candidateIds = collect((array) data_get($state, 'candidate_items.0.variant_candidates', []))
            ->map(fn (mixed $id): int => (int) $id)->filter()->unique();
        if ($candidateIds->isNotEmpty()) {
            $variants = collect((array) data_get($context, 'menu', []))
                ->filter(fn (mixed $product): bool => is_array($product) && $candidateIds->contains((int) ($product['id'] ?? 0)))
                ->map(fn (array $product): string => (string) ($product['name'] ?? ''))
                ->filter()->values()->all();

            return 'VocÃª prefere '.$this->humanJoin($variants).'?';
        }

        $presented = collect((array) data_get($state, 'presented_options.product_ids', []))->filter()->unique();
        if ($presented->isNotEmpty()) {
            return 'Qual dessas marmitas vocÃª prefere?';
        }

        $options = $this->marmitaOptions($context);
        if ($options === []) {
            return 'Qual produto você gostaria de pedir?';
        }

        return "Qual marmitex você prefere?\n\n".collect($options)->pluck('label')->implode("\n");
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $context */
    private function optionPrompt(array $state, array $context, string $slot, string $fallback): string
    {
        $goal = data_get($state, 'assistant_goal');
        $allowed = is_array($goal) && ($goal['slot'] ?? null) === $slot
            ? (array) ($goal['allowed_values'] ?? [])
            : [];
        $labels = collect($allowed)->pluck('label')->filter()->unique()->take(8)->values();
        if ($labels->isEmpty() && $slot === 'carne') {
            $labels = collect((array) data_get($context, 'daily_meats', []))->pluck('name')->filter()->unique()->take(8)->values();
        }

        return $labels->isEmpty() ? $fallback : $fallback.' Hoje temos: '.$this->humanJoin($labels->all()).'.';
    }

    /** @param array<string,mixed> $state */
    private function componentsPrompt(array $state): string
    {
        $names = collect((array) data_get($state, 'draft_order.items', []))
            ->flatMap(fn (mixed $item): array => is_array($item) ? (array) ($item['daily_component_candidates'] ?? []) : [])
            ->flatMap(fn (mixed $candidate): array => is_array($candidate) ? (array) ($candidate['names'] ?? []) : [])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->unique()
            ->values();

        return $names->isEmpty()
            ? 'Quais acompanhamentos do buffet você quer?'
            : 'Para o acompanhamento, você prefere '.$this->humanJoin($names->all()).'?';
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $context */
    private function itemConfirmation(array $state, array $context): string
    {
        $menu = collect((array) data_get($context, 'menu', []))->keyBy('id');
        $lines = collect((array) data_get($state, 'draft_order.items', []))->map(function (array $item) use ($menu): string {
            $product = $menu->get((int) ($item['menu_item_id'] ?? 0));
            $name = trim((string) data_get($product, 'name', $item['menu_item_slug'] ?? 'Item'));
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $choices = collect((array) ($item['selections'] ?? []))
                ->flatMap(fn (mixed $value): array => is_array($value) ? $value : (is_string($value) && trim($value) !== '' ? [$value] : []))
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '' && $value !== 'none')
                ->unique()
                ->implode(', ');
            $unit = (int) ($item['unit_price_cents'] ?? data_get($product, 'resolved_configuration.base_price_cents', data_get($product, 'base_price_cents', 0)));
            $price = $unit > 0 ? ' — '.$this->money($unit * $quantity) : '';

            return "*{$quantity}x {$name}*".($choices === '' ? '' : "\n{$choices}").$price;
        })->filter()->implode("\n\n");

        return "Confere seu item:\n\n{$lines}\n\nEstá tudo certo?";
    }

    /** @param array<string,mixed> $context */
    private function paymentPrompt(array $context): string
    {
        $methods = collect((array) data_get($context, 'payment.available_methods', []))
            ->map(fn (mixed $method): string => Str::headline((string) $method))
            ->filter()
            ->unique()
            ->values();

        return $methods->isEmpty()
            ? 'Como você prefere pagar?'
            : 'Como você prefere pagar? Temos '.$this->humanJoin($methods->all()).'.';
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    public function buildCandidateClarification(array $context): ?array
    {
        $text = $this->latestText($context);
        if ($text === '' || str_contains($text, 'combo')) {
            return null;
        }

        $componentResolution = $this->componentResolution($text, $context);
        $recognized = collect($this->resolvedComponents($componentResolution));
        $available = $recognized->where('available_today', true)->values();
        $unavailable = $recognized->where('available_today', false)->values();
        $ambiguousSelections = $this->ambiguousComponents($componentResolution);
        $ambiguousMeats = array_values(array_filter(
            $ambiguousSelections,
            fn (array $candidate): bool => ($candidate['type'] ?? null) === 'meat',
        ));

        if (preg_match('/\bn\s*[- ]?\s*8\b/', $text) === 1
            && preg_match('/\bn\s*[- ]?\s*8\s+(?:da\s+)?(?:casa|livre|tradicional)\b/', $text) !== 1
            && preg_match('/\bn\s*[- ]?\s*8\s+(?:so|somente|apenas)\s+bife\b/', $text) !== 1) {
            $variants = collect(data_get($context, 'menu', []))
                ->filter(fn (mixed $product): bool => is_array($product)
                    && in_array((string) ($product['rule'] ?? ''), ['n8_casa', 'n8_tradicional'], true)
                    && (bool) data_get($product, 'resolved_configuration.availability.available', true))
                ->unique('id')
                ->values();
            $priceMatches = $variants
                ->filter(fn (array $product): bool => $this->productPriceIsExplicitlyReferenced($text, $product))
                ->values();
            if ($variants->count() > 1) {
                if ($priceMatches->count() === 1 && $ambiguousMeats !== []) {
                    return null;
                }
                $names = $variants->pluck('name')->filter()->values();
                $candidateItem = [
                    ...$this->candidateItem('n8', $variants, $available),
                    'component_candidates' => $ambiguousSelections,
                ];
                $compatible = $priceMatches->count() === 1
                    ? $priceMatches
                    : $this->compatibleVariants($variants, $available);
                if ($compatible->count() === 1 && $ambiguousMeats === []) {
                    $variant = $compatible->first();
                    $selected = $this->selectedItemFromCandidate($candidateItem, $variant);

                    return $this->analysis(
                        '',
                        [],
                        $this->availabilityWarnings($unavailable),
                        [
                            'clarification_kind' => 'candidate_narrowed',
                            'candidate_item' => [
                                ...$candidateItem,
                                'variant' => (string) ($variant['slug'] ?? ''),
                                'variant_id' => (int) ($variant['id'] ?? 0),
                                'status' => 'resolved',
                            ],
                            'candidate_narrowing' => [
                                'resolved_product_id' => (int) ($variant['id'] ?? 0),
                                'source' => 'canonical_constraints',
                            ],
                            'semantic_delta_validated' => true,
                            'semantic_grounded_product_ids' => [(int) ($variant['id'] ?? 0)],
                            'recognized_components' => $this->explicitComponents($available),
                            'unavailable_components' => $this->explicitComponents($unavailable),
                        ],
                        [],
                        ['items' => [$selected], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                    );
                }
                $acknowledgement = $available->isEmpty()
                    ? ''
                    : 'Entendi também: '.$available->pluck('name')->implode(', ').'.';
                $unavailableNotice = $unavailable->isEmpty()
                    ? ''
                    : 'Hoje não temos '.$unavailable->pluck('name')->implode(', ').' disponível.';
                $question = 'Você prefere '.$this->humanJoin($names->all()).'? 😊';
                $replyMessages = array_values(array_filter([$acknowledgement, $unavailableNotice, $question]));

                return $this->analysis(
                    implode("\n\n", $replyMessages),
                    [
                        ['code' => 'N8_VARIANT', 'label' => 'N8 Casa ou N8 Livre'],
                        ...($ambiguousMeats === [] ? [] : [['code' => 'CARNE', 'label' => 'Carne']]),
                    ],
                    [
                        ...$this->availabilityWarnings($unavailable),
                        ...($ambiguousMeats === [] ? [] : [[
                            'code' => 'AMBIGUOUS_MEAT',
                            'message' => 'A carne citada corresponde a mais de uma opção disponível.',
                        ]]),
                    ],
                    [
                        'clarification_kind' => 'product_variant',
                        'product_candidate' => [
                            'family' => 'n8',
                            'variant_ids' => $variants->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                        ],
                        'candidate_product' => [
                            'family' => 'n8',
                            'variant_ids' => $variants->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                        ],
                        'candidate_item' => $candidateItem,
                        'recognized_components' => $this->explicitComponents($available),
                        'unavailable_components' => $this->explicitComponents($unavailable),
                    ],
                    $replyMessages,
                );
            }
        }

        $products = $this->matchedProducts($text, $context);
        $meats = $available->where('type', 'meat')->values();
        if ($products->count() !== 1 || $meats->count() < 2) {
            return null;
        }

        $product = $products->first();
        $facts = $this->decisionFacts->fromProduct($product);
        $maxChoices = (int) data_get($facts, 'meat_allowance.included_max', 0);
        if ($maxChoices < 1 || $meats->count() <= $maxChoices) {
            return null;
        }

        // The canonical rule explicitly prices additional traditional meats.
        // In that case this is not an irresolvable cardinality error: the domain
        // validator will quote it and the turn composer will explain the charge.
        if ((int) data_get($facts, 'meat_allowance.additional_unit_price_cents', 0) > 0) {
            return null;
        }

        $productName = (string) ($product['name'] ?? 'Essa opção');
        $question = "Na {$productName} vai ".($maxChoices === 1 ? '1 tipo de carne' : "até {$maxChoices} tipos de carne")
            .'. Você prefere '.$this->humanJoin($meats->pluck('name')->all()).'? 😊';

        return $this->analysis(
            $question,
            [['code' => 'CARNE', 'label' => 'Carne']],
            $this->availabilityWarnings($unavailable),
            [
                'clarification_kind' => 'meat_cardinality',
                'candidate_product' => [
                    'id' => (int) ($product['id'] ?? 0),
                    'slug' => (string) ($product['slug'] ?? ''),
                    'name' => $productName,
                ],
                'recognized_components' => $this->explicitComponents($available),
                'candidate_meat_ids' => $meats->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'max_meat_choices' => $maxChoices,
            ],
            [$question],
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    public function buildProductCompatibility(array $context): ?array
    {
        if (data_get($context, 'pending_clarification.type') !== 'product_selection') {
            return null;
        }

        $recognized = collect(data_get($context, 'pending_clarification.recognized_components', []))
            ->filter(fn (mixed $component): bool => is_array($component) && (int) ($component['id'] ?? 0) > 0)
            ->values();
        if ($recognized->isEmpty()) {
            return null;
        }

        $text = $this->latestText($context);
        $products = $this->matchedProducts($text, $context);
        if ($products->count() !== 1) {
            return null;
        }

        $product = $products->first();
        if (! str_ends_with((string) ($product['rule'] ?? ''), '_casa')) {
            return null;
        }

        $linkedIds = collect($product['groups'] ?? [])
            ->flatMap(fn (mixed $group): array => is_array($group) ? (array) ($group['options'] ?? []) : [])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $incompatible = $recognized->reject(fn (array $component): bool => $linkedIds->contains((int) $component['id']))->values();
        if ($incompatible->isEmpty()) {
            return null;
        }

        $fixed = collect($product['groups'] ?? [])
            ->filter(fn (mixed $group): bool => is_array($group) && ($group['selection_mode'] ?? null) === 'fixed')
            ->flatMap(fn (array $group): array => (array) ($group['options'] ?? []))
            ->pluck('name')
            ->filter()
            ->unique()
            ->values();
        $houseGroups = collect($product['groups'] ?? [])
            ->filter(fn (mixed $group): bool => is_array($group) && ($group['selection_actor'] ?? null) === 'house')
            ->pluck('label')
            ->filter()
            ->values();
        $composition = $fixed->merge($houseGroups)->implode(', ');
        $name = (string) ($product['name'] ?? 'essa opção da Casa');
        $first = "A {$name} tem composição definida pelo restaurante"
            .($composition === '' ? '.' : ": {$composition}.")
            .' Suas escolhas de '.$incompatible->pluck('name')->implode(', ').' não cabem nessa composição.';
        $second = 'Você quer seguir com a composição da Casa ou prefere uma marmitex Livre para manter suas escolhas?';

        return $this->analysis(
            $first."\n\n".$second,
            [['code' => 'MENU_ITEM', 'label' => 'Confirmação do produto']],
            [['code' => 'PRODUCT_INCOMPATIBLE_WITH_EXPLICIT_SELECTIONS', 'message' => 'A composição escolhida não aceita todas as escolhas explícitas do cliente.']],
            [
                'clarification_kind' => 'product_compatibility',
                'recognized_components' => $recognized->map(fn (array $component): array => [
                    ...$component,
                    'selection_source' => 'customer_explicit',
                ])->all(),
                'candidate_product' => [
                    'id' => (int) ($product['id'] ?? 0),
                    'slug' => (string) ($product['slug'] ?? ''),
                    'name' => $name,
                ],
                'incompatible_component_ids' => $incompatible->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ],
            [$first, $second],
        );
    }

    /** @param array<string,mixed> $context */
    public function isInformalOrder(array $context): bool
    {
        $text = $this->latestText($context);
        if ($text === ''
            || preg_match('/\b(?:ignore|ignora|ignorar)\s+(?:as\s+)?regras\b|\b(?:prompt|instruc(?:ao|oes))\s+(?:do\s+)?sistema\b/', $text) === 1
            || $this->containsProductReference($text, $context)) {
            return false;
        }

        $matches = $this->matchedComponents($text, $context);

        return count($matches) >= 2
            || ($this->hasOrderCue($text)
                && ($matches !== []
                    || $this->unambiguousTypo($text, $context) !== null
                    || $this->hasSpecificUnknownTerm($text)
                    || preg_match('/\bmarmit(?:a|ex)s?\b/', $text) === 1));
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    public function build(array $context): ?array
    {
        if (! $this->isInformalOrder($context)) {
            return null;
        }

        $text = $this->latestText($context);
        $matches = $this->matchedComponents($text, $context);
        $available = collect($matches)->where('available_today', true)->values();
        $unavailable = collect($matches)->where('available_today', false)->values();
        $typo = $matches === [] ? $this->unambiguousTypo($text, $context) : null;
        $products = $this->marmitaOptions($context);
        $availableMeats = collect(data_get($context, 'daily_meats', []))
            ->pluck('name')
            ->filter()
            ->unique()
            ->take(6)
            ->values();

        if (is_array($typo)) {
            return $this->analysis(
                'Você quis dizer '.(string) $typo['name'].'? 😊',
                [['code' => 'MENU_ITEM', 'label' => 'Produto']],
                [['code' => 'POSSIBLE_TYPO', 'message' => 'Foi encontrado um candidato próximo no cardápio atual.']],
                ['clarification_kind' => 'typo_confirmation', 'typo_candidate' => $typo],
            );
        }

        $sentences = [];
        if ($available->isNotEmpty()) {
            $sentences[] = 'Entendi: '.$available->pluck('name')->implode(', ').'.';
        }
        if ($unavailable->isNotEmpty()) {
            $sentences[] = 'Hoje não temos '.$unavailable->pluck('name')->implode(', ').' disponível.';
            if ($unavailable->contains(fn (array $component): bool => ($component['type'] ?? null) === 'meat') && $availableMeats->isNotEmpty()) {
                $sentences[] = 'As carnes disponíveis são: '.$availableMeats->implode(', ').'. Qual delas você prefere?';
            }
        }
        if ($matches === [] && $this->hasSpecificUnknownTerm($text)) {
            $sentences[] = 'Não encontrei essa opção no cardápio disponível de hoje.';
        }

        $acknowledgement = implode(' ', $sentences);
        $guidance = $this->marmitaGuidance($products);
        $question = ($guidance === '' ? '' : $guidance."\n\n").'Qual marmitex você gostaria de escolher entre essas marmitas?';
        if ($products !== []) {
            $question .= "\n\n".collect($products)->pluck('label')->implode("\n");
        }
        $replyMessages = array_values(array_filter([$acknowledgement, $question]));

        return $this->analysis(
            implode("\n\n", $replyMessages),
            [['code' => 'MENU_ITEM', 'label' => 'Produto']],
            $unavailable->map(fn (array $component): array => [
                'code' => 'ITEM_UNAVAILABLE',
                'message' => (string) $component['name'].' não está disponível no cardápio atual.',
            ])->all(),
            [
                'clarification_kind' => 'product_selection',
                'offered_product_ids' => collect($products)->pluck('id')->all(),
                'conversation_references' => collect($products)->map(fn (array $product): array => [
                    'product_id' => (int) $product['id'],
                    'slug' => (string) $product['slug'],
                    'name' => (string) $product['name'],
                ])->all(),
                'recognized_components' => $available->map(fn (array $component): array => [
                    'id' => (int) $component['id'],
                    'name' => (string) $component['name'],
                    'type' => (string) $component['type'],
                    'selection_source' => 'customer_explicit',
                ])->all(),
                'unavailable_components' => $unavailable->map(fn (array $component): array => [
                    'id' => (int) $component['id'],
                    'name' => (string) $component['name'],
                    'type' => (string) $component['type'],
                ])->all(),
            ],
            $replyMessages,
        );
    }

    /** @param array<string,mixed> $context @return list<array{id:int,slug:string,name:string,rule:string,price_cents:int,label:string,decision_facts:array<string,mixed>}> */
    public function marmitaOptions(array $context): array
    {
        return collect(data_get($context, 'menu', []))
            ->filter(fn (mixed $product): bool => is_array($product)
                && (bool) data_get($product, 'resolved_configuration.availability.available', true)
                && (str_contains((string) ($product['rule'] ?? ''), 'n5')
                    || str_contains((string) ($product['rule'] ?? ''), 'n8')
                    || str_contains((string) ($product['rule'] ?? ''), 'n9')
                    || str_contains($this->key((string) ($product['name'] ?? '')), 'separadinha')))
            ->unique('id')
            ->take(6)
            ->map(function (array $product): array {
                $name = (string) ($product['name'] ?? 'Marmitex');
                $price = (int) data_get($product, 'resolved_configuration.base_price_cents', $product['base_price_cents'] ?? 0);
                $facts = $this->decisionFacts->fromProduct($product);
                $summary = $this->decisionFacts->summary($facts);

                return [
                    'id' => (int) ($product['id'] ?? 0),
                    'slug' => (string) ($product['slug'] ?? ''),
                    'name' => $name,
                    'rule' => (string) ($product['rule'] ?? ''),
                    'price_cents' => $price,
                    'label' => "*{$name} — ".$this->money($price)."*\n".$summary,
                    'decision_facts' => $facts,
                ];
            })
            ->values()
            ->all();
    }

    /** @param list<array{id:int,slug:string,name:string,rule:string,price_cents:int,label:string,decision_facts:array<string,mixed>}> $products */
    private function marmitaGuidance(array $products): string
    {
        $rules = collect($products)->pluck('rule');
        $hasCasa = $rules->contains(fn (string $rule): bool => str_ends_with($rule, '_casa'));
        $hasLivre = $rules->contains(fn (string $rule): bool => str_ends_with($rule, '_tradicional'));

        return match (true) {
            $hasCasa && $hasLivre => 'Temos opções da Casa, com composição definida pelo restaurante, e Livres, que você monta com o buffet do dia.',
            $hasLivre => 'As opções Livres são montadas por você com o buffet do dia.',
            $hasCasa => 'As opções da Casa vêm com a composição definida pelo restaurante.',
            default => '',
        };
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function matchedComponents(string $text, array $context): array
    {
        return $this->resolvedComponents($this->componentResolution($text, $context));
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function componentResolution(string $text, array $context): array
    {
        $dailyMeatIds = collect((array) data_get($context, 'daily_meats', []))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $components = collect(data_get($context, 'operational_catalog.components', []))
            ->filter(fn (mixed $component): bool => is_array($component))
            ->map(fn (array $component): array => ($component['type'] ?? null) === 'meat' && $dailyMeatIds->isNotEmpty()
                ? [...$component, 'available_today' => $dailyMeatIds->contains((int) ($component['id'] ?? 0))]
                : $component);
        $tokens = $components->mapWithKeys(fn (array $component): array => [
            (int) ($component['id'] ?? 0) => collect(explode(' ', $this->key((string) ($component['name'] ?? ''))))
                ->filter(fn (string $token): bool => strlen($token) >= 4)
                ->values()
                ->all(),
        ]);
        $counts = $components
            ->where('available_today', true)
            ->flatMap(fn (array $component): array => $tokens->get((int) ($component['id'] ?? 0), []))
            ->countBy();
        $entities = $components->map(function (array $component) use ($tokens, $counts): array {
            $identityTokens = collect($tokens->get((int) ($component['id'] ?? 0), []));
            $leading = (string) $identityTokens->first();

            return [
                ...$component,
                'aliases' => $identityTokens
                    ->filter(fn (string $token): bool => $token === $leading || (int) $counts->get($token, 0) === 1)
                    ->map(fn (string $token): array => ['value' => $token, 'source' => 'inferred_alias'])
                    ->merge((array) ($component['aliases'] ?? []))
                    ->values()
                    ->all(),
            ];
        })->all();
        $resolution = $this->entities->resolve($text, $entities);

        return ['components' => $components, 'resolution' => $resolution];
    }

    /** @param array<string,mixed> $componentResolution @return list<array<string,mixed>> */
    private function resolvedComponents(array $componentResolution): array
    {
        $components = collect($componentResolution['components'] ?? []);
        $resolution = (array) ($componentResolution['resolution'] ?? []);

        return collect($resolution['resolved'])
            ->map(function (array $match) use ($components): ?array {
                $component = $components->firstWhere('id', (int) $match['canonical_id']);

                return is_array($component) ? [
                    ...$component,
                    'resolution' => [
                        'span' => [
                            'char_start' => (int) $match['char_start'],
                            'char_end' => (int) $match['char_end'],
                            'token_start' => (int) $match['token_start'],
                            'token_end' => (int) $match['token_end'],
                        ],
                        'provenance' => (string) $match['provenance'],
                        'identity_source' => (string) $match['identity_source'],
                    ],
                ] : null;
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $componentResolution @return list<array<string,mixed>> */
    private function ambiguousComponents(array $componentResolution, ?string $type = null): array
    {
        $components = collect($componentResolution['components'] ?? []);

        return collect(data_get($componentResolution, 'resolution.ambiguous', []))
            ->map(function (array $candidate) use ($components, $type): ?array {
                $matches = $components
                    ->whereIn('id', array_map('intval', (array) ($candidate['candidate_ids'] ?? [])));
                if ($type !== null) {
                    $matches = $matches->filter(
                        fn (array $component): bool => (string) ($component['type'] ?? '') === $type,
                    );
                }
                $matches = $matches->values();
                if ($matches->count() < 2) {
                    return null;
                }

                return [
                    'token' => (string) ($candidate['matched_text'] ?? ''),
                    'component_ids' => $matches->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    'names' => $matches->pluck('name')->filter()->values()->all(),
                    'type' => $type ?? (string) data_get($matches->first(), 'type', 'component'),
                    'status' => 'candidate',
                    'provenance' => 'customer_explicit',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function candidateItem(string $family, $variants, $components): array
    {
        $components = collect($components);

        return [
            'family' => $family,
            'variant' => null,
            'status' => 'candidate',
            'variant_candidates' => collect($variants)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'quantity' => 1,
            'daily_component_ids' => $components->where('type', '!=', 'meat')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'meat_component_ids' => $components->where('type', 'meat')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'meats' => $components->where('type', 'meat')->pluck('name')->filter()->values()->all(),
            'components' => $this->explicitComponents($components),
            'additions' => [],
            'notes' => '',
            'provenance' => 'customer_explicit',
        ];
    }

    private function compatibleVariants($variants, $components)
    {
        $componentIds = collect($components)->pluck('id')->map(fn (mixed $id): int => (int) $id)->filter()->unique();
        $meatCount = collect($components)->where('type', 'meat')->unique('id')->count();

        return collect($variants)->filter(function (array $variant) use ($componentIds, $meatCount): bool {
            $facts = $this->decisionFacts->fromProduct($variant);
            $max = (int) data_get($facts, 'meat_allowance.included_max', 0);
            if ($max > 0 && $meatCount > $max && (int) data_get($facts, 'meat_allowance.additional_unit_price_cents', 0) < 1) {
                return false;
            }
            if (! str_ends_with((string) ($variant['rule'] ?? ''), '_casa')) {
                return true;
            }
            $linked = collect((array) ($variant['groups'] ?? []))
                ->flatMap(fn (mixed $group): array => is_array($group) ? (array) ($group['options'] ?? []) : [])
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->filter()->unique();

            return $componentIds->diff($linked)->isEmpty();
        })->values();
    }

    /** @return array<string,mixed> */
    private function selectedItemFromCandidate(array $candidate, array $variant): array
    {
        return [
            'menu_item_id' => (int) ($variant['id'] ?? 0),
            'menu_item_slug' => (string) ($variant['slug'] ?? ''),
            'quantity' => max(1, (int) ($candidate['quantity'] ?? 1)),
            'selections' => ['meat' => null, 'meats' => array_values((array) ($candidate['meats'] ?? []))],
            'daily_component_ids' => array_values((array) ($candidate['daily_component_ids'] ?? [])),
            'daily_component_candidates' => collect((array) ($candidate['component_candidates'] ?? []))
                ->reject(fn (array $component): bool => ($component['type'] ?? null) === 'meat')
                ->map(fn (array $component): array => collect($component)->except('type')->all())
                ->values()
                ->all(),
            'removed_components' => [],
            'item_notes' => (string) ($candidate['notes'] ?? ''),
            'candidate_origin' => ['family' => (string) ($candidate['family'] ?? ''), 'provenance' => (string) ($candidate['provenance'] ?? '')],
        ];
    }

    /** @return list<array{id:int,name:string,type:string,selection_source:string}> */
    private function explicitComponents($components): array
    {
        return collect($components)->map(fn (array $component): array => [
            'id' => (int) $component['id'],
            'name' => (string) $component['name'],
            'type' => (string) $component['type'],
            'selection_source' => 'customer_explicit',
        ])->values()->all();
    }

    /** @return list<array{code:string,message:string}> */
    private function availabilityWarnings($components): array
    {
        return collect($components)->map(fn (array $component): array => [
            'code' => 'ITEM_UNAVAILABLE',
            'message' => (string) $component['name'].' não está disponível no cardápio atual.',
        ])->values()->all();
    }

    /** @param list<string> $values */
    private function humanJoin(array $values): string
    {
        $values = array_values(array_filter(array_map('trim', $values)));
        if (count($values) < 2) {
            return $values[0] ?? 'uma das opções';
        }

        $last = array_pop($values);

        return implode(', ', $values).' ou '.$last;
    }

    /** @param array<string,mixed> $context @return array{id:int,name:string,type:string}|null */
    private function unambiguousTypo(string $text, array $context): ?array
    {
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', $text) ?: [], fn (string $token): bool => strlen($token) >= 5));
        $candidates = collect(data_get($context, 'operational_catalog.components', []))
            ->filter(fn (mixed $component): bool => is_array($component) && ($component['available_today'] ?? false) === true)
            ->filter(function (array $component) use ($tokens): bool {
                $nameTokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', $this->key((string) ($component['name'] ?? ''))) ?: [], fn (string $token): bool => strlen($token) >= 5));

                return collect($tokens)->contains(fn (string $token): bool => collect($nameTokens)->contains(
                    fn (string $candidate): bool => abs(strlen($token) - strlen($candidate)) <= 1
                        && (levenshtein($token, $candidate) === 1
                            || (strlen($token) === strlen($candidate)
                                && $token[0] === $candidate[0]
                                && levenshtein($token, $candidate) === 2)),
                ));
            })
            ->map(fn (array $component): array => [
                'id' => (int) ($component['id'] ?? 0),
                'name' => (string) ($component['name'] ?? ''),
                'type' => (string) ($component['type'] ?? ''),
            ])
            ->unique('id')
            ->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** @param array<string,mixed> $context */
    private function containsProductReference(string $text, array $context): bool
    {
        if (preg_match('/\bn\s*[- ]?\s*(?:5|8|9)\b|\bseparadinha\b/', $text) === 1) {
            return true;
        }

        return $this->matchedProducts($text, $context)->isNotEmpty();
    }

    /** @param array<string,mixed> $context */
    private function matchedProducts(string $text, array $context)
    {
        $products = collect(data_get($context, 'menu', []))
            ->filter(fn (mixed $product): bool => is_array($product) && (int) ($product['id'] ?? 0) > 0)
            ->values();
        $entities = $products->map(function (array $product): array {
            $aliases = match ((string) ($product['rule'] ?? '')) {
                'n5_casa' => ['n5', 'n 5', 'n5 casa', 'n 5 casa', 'n5 da casa', 'n 5 da casa'],
                'n8_casa' => ['n8 casa', 'n 8 casa', 'n8 da casa', 'n 8 da casa'],
                'n8_tradicional' => ['n8 livre', 'n 8 livre', 'n8 tradicional', 'n 8 tradicional'],
                'n9_tradicional' => ['n9', 'n 9', 'n9 livre', 'n 9 livre', 'n9 tradicional', 'n 9 tradicional'],
                default => [],
            };

            return [
                'id' => (int) $product['id'],
                'slug' => (string) ($product['slug'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'type' => 'product',
                'available_today' => (bool) data_get($product, 'resolved_configuration.availability.available', true),
                'aliases' => $aliases,
            ];
        })->all();
        $resolution = $this->entities->resolve($text, $entities);
        $ids = collect($resolution['resolved'])->pluck('canonical_id')->map(fn (mixed $id): int => (int) $id);

        return $products->whereIn('id', $ids)->values();
    }

    /** @param array<string,mixed> $product */
    private function productPriceIsExplicitlyReferenced(string $text, array $product): bool
    {
        $cents = (int) data_get($product, 'resolved_configuration.base_price_cents', $product['base_price_cents'] ?? 0);
        if ($cents < 1) {
            return false;
        }

        $whole = intdiv($cents, 100);
        $decimal = $cents % 100;
        $amount = $decimal === 0
            ? (string) $whole.'(?:\s+00)?'
            : (string) $whole.'\s+'.str_pad((string) $decimal, 2, '0', STR_PAD_LEFT);

        return preg_match('/\bn\s*[- ]?\s*8(?:\s+(?:de|por))?\s+(?:r\s*)?'.$amount.'\b/', $text) === 1;
    }

    private function hasOrderCue(string $text): bool
    {
        return preg_match('/\b(quero|queria|gostaria|manda|mandar|me\s+da|me\s+ve|marmit(?:a|ex)s?|pedido)\b/', $text) === 1;
    }

    private function hasSpecificUnknownTerm(string $text): bool
    {
        if (! $this->hasOrderCue($text)) {
            return false;
        }

        $residual = preg_replace('/\b(?:quero|qro|queria|gostaria|pedir|de|uma|um|marmitas?|marmitexs?|pedido|me|ve|manda|por\s+favor|grande|media|pequena|qual|quais|que|sao|as|os|voces|vcs|tem|hoje|ai)\b/', ' ', $text) ?: '';

        return preg_match('/\b[a-z0-9]{3,}\b/', $residual) === 1;
    }

    /** @param array<string,mixed> $context */
    private function latestText(array $context): string
    {
        return $this->key((string) data_get($context, 'latest_message.body', ''));
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    /** @param list<array<string,string>> $missing @param list<array<string,string>> $warnings @param array<string,mixed> $metadata @param list<string> $replyMessages @param array<string,mixed>|null $draftOrder @return array<string,mixed> */
    private function analysis(string $reply, array $missing, array $warnings, array $metadata, array $replyMessages = [], ?array $draftOrder = null): array
    {
        return [
            'intent' => 'ORDER_CREATE',
            'confidence' => 1,
            'summary' => 'Pedido informal aguardando uma escolha normal do cliente.',
            'draft_order' => $draftOrder ?? ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => $missing,
            'warnings' => $warnings,
            'suggested_reply' => $reply,
            'reply_messages' => $replyMessages,
            'metadata' => ['reply_source' => 'order_clarification', ...$metadata],
        ];
    }
}
