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
        $intents = ['GREETING', 'MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE', 'ORDER_STATUS', 'PAYMENT_QUESTION', 'DELIVERY_QUESTION', 'GENERAL_QUESTION', 'GENERAL_MESSAGE', 'HUMAN_REQUEST', 'UNKNOWN'];
        $intent = strtoupper((string) ($raw['intent'] ?? 'UNKNOWN'));
        $warnings = collect($raw['warnings'] ?? [])->map(fn ($value) => ['code' => is_array($value) ? (string) ($value['code'] ?? 'COPILOT_WARNING') : 'COPILOT_WARNING', 'message' => $this->text(is_array($value) ? ($value['message'] ?? '') : $value, 240)])->filter(fn ($value) => $value['message'] !== '')->values()->all();
        $items = collect(data_get($raw, 'draft_order.items', []))->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item) use (&$warnings): array {
            $note = $this->noteSanitizer->sanitize($this->text($item['item_notes'] ?? $item['notes'] ?? null, 500));
            if ($note['rejected']) {
                $warnings[] = [
                    'code' => 'SENSITIVE_ITEM_NOTE_REJECTED',
                    'message' => 'Uma instrucao financeira ou operacional foi removida da observacao do item.',
                ];
            }

            return ['menu_item_id' => $this->integer($item['menu_item_id'] ?? null), 'menu_item_slug' => $this->text($item['menu_item_slug'] ?? $item['product'] ?? null, 80), 'quantity' => $this->integer($item['quantity'] ?? 1), 'selections' => is_array($item['selections'] ?? null) ? $item['selections'] : [], 'removed_components' => collect($item['removed_components'] ?? [])->filter(fn (mixed $value): bool => is_string($value))->map(fn ($value) => $this->text($value, 80))->filter()->unique()->values()->all(), 'item_notes' => $note['note']];
        })->values()->all();
        $missing = $this->identity->missing(is_array($raw['missing_information'] ?? null) ? $raw['missing_information'] : []);

        return new CopilotAnalysis(in_array($intent, $intents, true) ? $intent : 'UNKNOWN', max(0, min(1, (float) ($raw['confidence'] ?? 0))), $this->text($raw['summary'] ?? '', 500), ['items' => $items, 'fulfillment' => in_array(data_get($raw, 'draft_order.fulfillment'), ['delivery', 'pickup'], true) ? data_get($raw, 'draft_order.fulfillment') : null, 'address' => $this->text(data_get($raw, 'draft_order.address'), 500), 'payment_method' => $this->text(data_get($raw, 'draft_order.payment_method'), 40)], $missing, $warnings, $this->text($raw['suggested_reply'] ?? '', 1000), true, ['provider' => $provider, ...$metadata]);
    }

    private function text(mixed $value, int $limit): string
    {
        return Str::squish(Str::limit((string) $value, $limit, ''));
    }

    private function integer(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }
}
