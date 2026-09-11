<?php

namespace App\Services\Ai;

use App\Data\Ai\CopilotAnalysis;
use Illuminate\Support\Str;

class ConversationCopilotNormalizer
{
    public function __construct(
        private readonly CopilotCanonicalIdentity $identity,
        private readonly CopilotSensitiveItemNoteSanitizer $noteSanitizer,
    ) {}

    /** @param array<string,mixed> $raw */
    public function normalize(array $raw, string $provider, array $metadata = []): CopilotAnalysis
    {
        $intents = ['GREETING', 'MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST', 'ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE', 'ORDER_STATUS', 'PAYMENT_QUESTION', 'PAYMENT_CONFIRMATION', 'DELIVERY_QUESTION', 'GENERAL_QUESTION', 'GENERAL_MESSAGE', 'HUMAN_REQUEST', 'UNKNOWN'];
        $intent = strtoupper((string) ($raw['intent'] ?? 'UNKNOWN'));
        $warnings = collect($raw['warnings'] ?? [])->map(fn ($value) => ['code' => is_array($value) ? (string) ($value['code'] ?? 'COPILOT_WARNING') : 'COPILOT_WARNING', 'message' => $this->text(is_array($value) ? ($value['message'] ?? '') : $value, 240)])->filter(fn ($value) => $value['message'] !== '')->values()->all();
        $items = collect(data_get($raw, 'draft_order.items', []))->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item) use (&$warnings, $provider): array {
            $note = $this->noteSanitizer->sanitize($this->text($item['item_notes'] ?? $item['notes'] ?? null, 500));
            if ($note['rejected']) {
                $warnings[] = [
                    'code' => 'SENSITIVE_ITEM_NOTE_REJECTED',
                    'message' => 'Uma instrucao financeira ou operacional foi removida da observacao do item.',
                ];
            }

            $normalized = ['menu_item_id' => $this->integer($item['menu_item_id'] ?? null), 'menu_item_slug' => $this->text($item['menu_item_slug'] ?? $item['product'] ?? null, 80), 'quantity' => $this->integer($item['quantity'] ?? 1), 'selections' => is_array($item['selections'] ?? null) ? $item['selections'] : [], 'removed_components' => collect($item['removed_components'] ?? [])->filter(fn (mixed $value): bool => is_string($value))->map(fn ($value) => $this->text($value, 80))->filter()->unique()->values()->all(), 'item_notes' => $note['note']];
            if ($provider === 'deterministic') {
                $normalized['daily_component_ids'] = collect((array) ($item['daily_component_ids'] ?? []))
                    ->map(fn (mixed $id): ?int => $this->integer($id))->filter()->unique()->values()->all();
                $normalized['daily_component_candidates'] = collect((array) ($item['daily_component_candidates'] ?? []))
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->values()
                    ->all();
                $normalized['candidate_origin'] = is_array($item['candidate_origin'] ?? null) ? $item['candidate_origin'] : [];
            }

            return $normalized;
        })->values()->all();
        $missing = $this->identity->missing(is_array($raw['missing_information'] ?? null) ? $raw['missing_information'] : []);
        $replyMessages = collect(is_array($raw['reply_messages'] ?? null) ? $raw['reply_messages'] : [])
            ->filter(fn (mixed $message): bool => is_string($message))
            ->map(fn (string $message): string => $this->replyText($message, 1800))
            ->filter()
            ->take(3)
            ->values()
            ->all();

        $interpretation = $this->interpretation($raw['interpretation'] ?? null);

        return new CopilotAnalysis(in_array($intent, $intents, true) ? $intent : 'UNKNOWN', max(0, min(1, (float) ($raw['confidence'] ?? 0))), $this->text($raw['summary'] ?? '', 500), ['items' => $items, 'fulfillment' => in_array(data_get($raw, 'draft_order.fulfillment'), ['delivery', 'pickup'], true) ? data_get($raw, 'draft_order.fulfillment') : null, 'address' => $this->text(data_get($raw, 'draft_order.address'), 500), 'payment_method' => $this->text(data_get($raw, 'draft_order.payment_method'), 40)], $missing, $warnings, $this->replyText($raw['suggested_reply'] ?? '', 1800), true, ['provider' => $provider, ...($interpretation === null ? [] : ['semantic_interpretation' => $interpretation]), ...$metadata], $replyMessages);
    }

    private function text(mixed $value, int $limit): string
    {
        return Str::squish(Str::limit((string) $value, $limit, ''));
    }

    private function replyText(mixed $value, int $limit): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = collect(explode("\n", $text))
            ->map(fn (string $line): string => Str::squish($line))
            ->implode("\n");
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? '';

        return Str::limit($text, $limit, '');
    }

    private function integer(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }

    /** @return array<string,mixed>|null */
    private function interpretation(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $allowedIntents = ['generic_order', 'order_request', 'compare_products', 'ask_product_information', 'ask_pending_slot_options', 'answer_pending_slot', 'correct_order', 'select_option', 'confirm', 'deny', 'human_request', 'other'];
        $allowedFacts = ['product_details', 'pending_slot_options', 'payment_methods', 'daily_menu', 'daily_meats', 'active_order'];
        $allowedOperations = ['add', 'remove', 'replace', 'set'];
        $intents = collect(is_array($value['intents'] ?? null) ? $value['intents'] : [])
            ->map(fn (mixed $intent): string => Str::of((string) $intent)->snake()->lower()->toString())
            ->filter(fn (string $intent): bool => in_array($intent, $allowedIntents, true))
            ->unique()
            ->take(5)
            ->values()
            ->all();
        $operations = collect(is_array($value['state_operations'] ?? null) ? $value['state_operations'] : [])
            ->filter(fn (mixed $operation): bool => is_array($operation))
            ->map(fn (array $operation): array => [
                'operation' => Str::of((string) ($operation['operation'] ?? ''))->snake()->lower()->toString(),
                'subject' => $this->text($operation['subject'] ?? '', 40),
                'from' => $this->text($operation['from'] ?? '', 100),
                'to' => $this->text($operation['to'] ?? '', 100),
            ])
            ->filter(fn (array $operation): bool => in_array($operation['operation'], $allowedOperations, true) && $operation['subject'] !== '')
            ->take(6)
            ->values()
            ->all();

        $orderDelta = is_array($value['order_delta'] ?? null) ? $value['order_delta'] : [];

        return [
            'intents' => $intents,
            'discourse' => collect(is_array($value['discourse'] ?? null) ? $value['discourse'] : [])
                ->map(fn (mixed $entry): string => $this->text($entry, 60))->filter()->take(8)->values()->all(),
            'referents' => collect(is_array($value['referents'] ?? null) ? $value['referents'] : [])
                ->map(fn (mixed $entry): string => $this->text($entry, 100))->filter()->take(8)->values()->all(),
            'informational_requests' => collect(is_array($value['informational_requests'] ?? null) ? $value['informational_requests'] : [])
                ->map(fn (mixed $entry): string => Str::of((string) $entry)->snake()->lower()->toString())->filter()->take(8)->values()->all(),
            'order_delta' => [
                'product_reference' => $this->text($orderDelta['product_reference'] ?? '', 120),
                'components' => $this->stringList($orderDelta['components'] ?? [], 12),
                'meats' => $this->stringList($orderDelta['meats'] ?? [], 8),
                'beverages' => $this->stringList($orderDelta['beverages'] ?? [], 8),
                'exclusions' => $this->stringList($orderDelta['exclusions'] ?? [], 10),
                'corrections' => $this->stringList($orderDelta['corrections'] ?? [], 8),
                'replacements' => $this->stringList($orderDelta['replacements'] ?? [], 8),
                'quantity' => $this->integer($orderDelta['quantity'] ?? null),
                'fulfillment' => in_array($orderDelta['fulfillment'] ?? null, ['delivery', 'pickup'], true) ? $orderDelta['fulfillment'] : null,
                'address' => $this->text($orderDelta['address'] ?? '', 300),
                'payment_method' => $this->text($orderDelta['payment_method'] ?? '', 40),
            ],
            'confirmation' => in_array($value['confirmation'] ?? null, ['yes', 'no', 'unknown'], true) ? $value['confirmation'] : 'unknown',
            'negations' => $this->stringList($value['negations'] ?? [], 8),
            'human_requested' => ($value['human_requested'] ?? false) === true,
            'semantic_confidence' => max(0, min(1, (float) ($value['semantic_confidence'] ?? 0))),
            'clarification_needed' => ($value['clarification_needed'] ?? false) === true,
            'subject' => $this->text($value['subject'] ?? '', 60),
            'candidate_value' => $this->text($value['candidate_value'] ?? '', 120),
            'candidate_values' => collect(is_array($value['candidate_values'] ?? null) ? $value['candidate_values'] : [])
                ->map(fn (mixed $candidate): string => $this->text($candidate, 120))->filter()->take(8)->values()->all(),
            'target_products' => collect(is_array($value['target_products'] ?? null) ? $value['target_products'] : [])
                ->map(fn (mixed $product): string => $this->text($product, 120))->filter()->take(6)->values()->all(),
            'reference' => $this->text($value['reference'] ?? '', 80),
            'selected_option_index' => $this->integer($value['selected_option_index'] ?? null),
            'mutates_order' => ($value['mutates_order'] ?? false) === true,
            'facts_needed' => collect(is_array($value['facts_needed'] ?? null) ? $value['facts_needed'] : [])
                ->map(fn (mixed $fact): string => Str::of((string) $fact)->snake()->lower()->toString())
                ->filter(fn (string $fact): bool => in_array($fact, $allowedFacts, true))
                ->unique()->values()->all(),
            'reply_goal' => $this->text($value['reply_goal'] ?? '', 80),
            'state_operations' => $operations,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $limit): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $entry): string => $this->text($entry, 160))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }
}
