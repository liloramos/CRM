<?php

namespace App\Services\Ai;

use App\Data\Ai\CopilotAnalysis;
use Illuminate\Support\Str;

class ConversationCopilotNormalizer
{
    /** @param array<string,mixed> $raw */
    public function normalize(array $raw, string $provider, array $metadata = []): CopilotAnalysis
    {
        $intents = ['GREETING', 'MENU_REQUEST', 'ORDER_CREATE', 'ORDER_CHANGE', 'ORDER_STATUS', 'PAYMENT_QUESTION', 'DELIVERY_QUESTION', 'GENERAL_QUESTION', 'HUMAN_REQUEST', 'UNKNOWN'];
        $intent = strtoupper((string) ($raw['intent'] ?? 'UNKNOWN'));
        $items = collect(data_get($raw, 'draft_order.items', []))->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item): array {
            return ['menu_item_id' => $this->integer($item['menu_item_id'] ?? null), 'menu_item_slug' => $this->text($item['menu_item_slug'] ?? $item['product'] ?? null, 80), 'quantity' => $this->integer($item['quantity'] ?? 1), 'selections' => is_array($item['selections'] ?? null) ? $item['selections'] : [], 'removed_components' => collect($item['removed_components'] ?? [])->filter(fn (mixed $value): bool => is_string($value))->map(fn ($value) => $this->text($value, 80))->filter()->unique()->values()->all(), 'item_notes' => $this->text($item['item_notes'] ?? $item['notes'] ?? null, 500)];
        })->values()->all();
        $missing = collect($raw['missing_information'] ?? [])->map(fn ($value) => ['code' => strtoupper((string) (is_array($value) ? ($value['code'] ?? 'UNKNOWN') : $value)), 'label' => (string) (is_array($value) ? ($value['label'] ?? $value['code'] ?? '') : $value)])->filter(fn ($value) => $value['label'] !== '')->unique('code')->values()->all();
        $warnings = collect($raw['warnings'] ?? [])->map(fn ($value) => ['code' => is_array($value) ? (string) ($value['code'] ?? 'COPILOT_WARNING') : 'COPILOT_WARNING', 'message' => $this->text(is_array($value) ? ($value['message'] ?? '') : $value, 240)])->filter(fn ($value) => $value['message'] !== '')->values()->all();

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
