<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Product;
use App\Services\Orders\CustomerActiveOrderResolver;

final class CopilotOrderProposalPresenter
{
    public function __construct(private readonly CustomerActiveOrderResolver $activeOrders) {}

    /** @param array<string,mixed> $safe @return array<string,mixed> */
    public function present(Conversation $conversation, array $safe): array
    {
        $items = collect(data_get($safe, 'draft_order.items', []))
            ->filter(fn (mixed $item): bool => is_array($item) && (int) ($item['menu_item_id'] ?? 0) > 0)
            ->values();
        $names = Product::query()
            ->where('company_id', $conversation->company_id)
            ->whereIn('id', $items->pluck('menu_item_id')->unique()->all())
            ->pluck('name', 'id');
        $blockingReasons = [];

        if ($items->isEmpty()) {
            $blockingReasons[] = 'Nao ha produto seguro para preencher o rascunho.';
        }
        if ($items->count() > 1) {
            $blockingReasons[] = 'Revise os itens separadamente antes de aplicar a proposta.';
        }
        if ($this->activeOrders->forConversation($conversation) !== null) {
            $blockingReasons[] = 'Existe um pedido ativo; escolha o alvo manualmente antes de aplicar a proposta.';
        }

        $hasMeatConflict = collect(data_get($safe, 'warnings', []))
            ->contains(fn (array $warning): bool => ($warning['code'] ?? null) === 'CONFLICTING_MEAT_REQUEST');
        $warnings = array_map(fn (array $warning): array => [
            ...$warning,
            'message' => $this->operationalWarning((string) ($warning['message'] ?? ''), (string) ($warning['code'] ?? '')),
        ], array_values(array_filter(data_get($safe, 'warnings', []), fn (array $warning): bool => ! (
            $hasMeatConflict
            && ($warning['code'] ?? null) === 'DOMAIN_SELECTION_REJECTED'
        ))));
        $missing = array_map(fn (array $item): array => [
            ...$item,
            'message' => $this->operationalMissing((string) ($item['code'] ?? ''), (string) ($item['label'] ?? '')),
        ], array_values(data_get($safe, 'missing_information', [])));
        $applyability = $blockingReasons !== []
            ? 'BLOCKED'
            : ($missing !== [] ? 'PARTIAL' : 'READY');

        return [
            'source' => 'safe_result',
            'intent' => (string) ($safe['intent'] ?? 'UNKNOWN'),
            'applyability' => $applyability,
            'can_apply' => $applyability !== 'BLOCKED',
            'blocking_reasons' => $blockingReasons,
            'items' => $items->map(fn (array $item): array => [
                'menu_item_id' => (int) $item['menu_item_id'],
                'menu_item_slug' => (string) ($item['menu_item_slug'] ?? ''),
                'product_name' => (string) ($names->get($item['menu_item_id']) ?? $item['menu_item_slug'] ?? 'Produto'),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'selections' => is_array($item['selections'] ?? null) ? $item['selections'] : [],
                'removed_components' => array_values($item['removed_components'] ?? []),
                'item_notes' => (string) ($item['item_notes'] ?? ''),
            ])->all(),
            'fulfillment' => data_get($safe, 'draft_order.fulfillment'),
            'missing_information' => $missing,
            'warnings' => $warnings,
            'requires_human_review' => true,
        ];
    }

    private function operationalWarning(string $message, string $code): string
    {
        $normalized = mb_strtolower($message);

        if (strtoupper($code) === 'CONFLICTING_MEAT_REQUEST') {
            return 'O cliente informou opções de carne incompatíveis.';
        }
        if (str_contains($normalized, 'em carne') || strtoupper($code) === 'UNRESOLVED_MEAT') {
            return 'Falta escolher a carne.';
        }
        if (str_contains($normalized, 'em salada')) {
            return 'Falta escolher a salada.';
        }

        return $message;
    }

    private function operationalMissing(string $code, string $label): string
    {
        return match (strtoupper($code)) {
            'CARNE' => 'Falta escolher a carne.',
            'SALADA' => 'Falta escolher a salada.',
            'ADDRESS' => 'Falta informar o endereço.',
            'MENU_ITEM' => 'Falta identificar a marmita.',
            default => $label !== '' ? "Falta: {$label}." : 'Há uma informação pendente.',
        };
    }
}
