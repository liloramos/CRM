<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Product;
use App\Services\Orders\CustomerActiveOrderResolver;
use Illuminate\Support\Collection;

final class CopilotOrderProposalPresenter
{
    public function __construct(
        private readonly CustomerActiveOrderResolver $activeOrders,
        private readonly CopilotProductEligibility $eligibility,
    ) {}

    /** @param array<string,mixed> $safe @return array<string,mixed> */
    public function present(Conversation $conversation, array $safe, array $context = []): array
    {
        $items = collect(data_get($safe, 'draft_order.items', []))
            ->filter(fn (mixed $item): bool => is_array($item) && (int) ($item['menu_item_id'] ?? 0) > 0)
            ->values();
        $products = $this->eligibility->apply(Product::query())
            ->where('company_id', $conversation->company_id)
            ->whereIn('id', $items->pluck('menu_item_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $items = $this->orderByInboundReference($items, $products, $context);
        $names = $products->pluck('name', 'id');
        $blockingReasons = [];

        if ($items->isEmpty()) {
            $blockingReasons[] = (string) ($safe['intent'] ?? '') === 'ORDER_CHANGE'
                ? 'A alteracao foi identificada e precisa de revisao humana. Nenhuma mudanca foi aplicada ao pedido.'
                : 'Nao ha produto seguro para preencher o rascunho.';
        }
        $target = $this->target($conversation, $blockingReasons, (string) ($safe['intent'] ?? 'UNKNOWN'));

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
        $hasActionableWarning = $this->warningRequiresHumanAction($warnings);
        $applyability = $blockingReasons !== []
            ? 'BLOCKED'
            : ($missing !== [] || $hasActionableWarning ? 'PARTIAL' : 'READY');

        return [
            'source' => 'safe_result',
            'intent' => (string) ($safe['intent'] ?? 'UNKNOWN'),
            'applyability' => $applyability,
            'can_apply' => $applyability !== 'BLOCKED',
            'blocking_reasons' => $blockingReasons,
            'items' => $items->map(function (array $item, int $index) use ($missing, $warnings, $names): array {
                $itemMissing = array_values(array_filter($missing, fn (array $entry): bool => array_key_exists('item_index', $entry) && (int) $entry['item_index'] === $index));
                $itemWarnings = array_values(array_filter($warnings, fn (array $entry): bool => array_key_exists('item_index', $entry) && (int) $entry['item_index'] === $index));

                return [
                    'menu_item_id' => (int) $item['menu_item_id'],
                    'menu_item_slug' => (string) ($item['menu_item_slug'] ?? ''),
                    'product_name' => (string) ($names->get($item['menu_item_id']) ?? $item['menu_item_slug'] ?? 'Produto'),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'selections' => is_array($item['selections'] ?? null) ? $item['selections'] : [],
                    'removed_components' => array_values($item['removed_components'] ?? []),
                    'item_notes' => (string) ($item['item_notes'] ?? ''),
                    'applyability' => $itemMissing !== [] || $this->warningRequiresHumanAction($itemWarnings) ? 'PARTIAL' : 'READY',
                    'missing_information' => $itemMissing,
                    'warnings' => $itemWarnings,
                    'operation' => 'ADD_ITEM',
                ];
            })->all(),
            'target' => $target,
            'fulfillment' => data_get($safe, 'draft_order.fulfillment'),
            'delivery_address' => data_get($safe, 'draft_order.address'),
            'payment_method' => data_get($safe, 'draft_order.payment_method'),
            'missing_information' => $missing,
            'warnings' => $warnings,
            'requires_human_review' => true,
        ];
    }

    /** @param list<string> $blockingReasons @return array<string,mixed> */
    private function target(Conversation $conversation, array &$blockingReasons, string $intent): array
    {
        $activeOrder = $this->activeOrders->forConversation($conversation);
        if ($activeOrder === null) {
            return [
                'state' => 'NEW_ORDER',
                'requires_human_selection' => false,
                'choices' => ['NEW_ORDER'],
                'default_choice' => 'NEW_ORDER',
                'active_order' => null,
            ];
        }

        $sameCustomer = ! $conversation->customer_id
            || ! $activeOrder->payer_customer_id
            || (int) $activeOrder->payer_customer_id === (int) $conversation->customer_id;
        if ((int) $activeOrder->company_id !== (int) $conversation->company_id || ! $sameCustomer) {
            $blockingReasons[] = 'O pedido em andamento nao pertence a esta conversa.';

            return [
                'state' => 'UNRESOLVED',
                'requires_human_selection' => true,
                'choices' => [],
                'default_choice' => null,
                'active_order' => null,
            ];
        }

        if ($intent === 'ORDER_CHANGE') {
            return [
                'state' => 'ACTIVE_ORDER',
                'requires_human_selection' => false,
                'choices' => [],
                'default_choice' => null,
                'active_order' => [
                    'id' => (string) $activeOrder->id,
                    'code' => (string) $activeOrder->code,
                    'company_id' => (int) $activeOrder->company_id,
                    'customer_id' => $activeOrder->payer_customer_id ? (string) $activeOrder->payer_customer_id : null,
                ],
            ];
        }

        return [
            'state' => 'UNRESOLVED',
            'requires_human_selection' => true,
            'choices' => ['NEW_ORDER', 'ACTIVE_ORDER'],
            'default_choice' => null,
            'active_order' => [
                'id' => (string) $activeOrder->id,
                'code' => (string) $activeOrder->code,
                'company_id' => (int) $activeOrder->company_id,
                'customer_id' => $activeOrder->payer_customer_id ? (string) $activeOrder->payer_customer_id : null,
            ],
        ];
    }

    private function operationalWarning(string $message, string $code): string
    {
        $normalized = mb_strtolower($message);

        if (strtoupper($code) === 'UNGROUNDED_REMOVAL') {
            return 'O Copiloto descartou uma alteração que não foi confirmada pelo cliente.';
        }
        if (strtoupper($code) === 'INVALID_REMOVAL') {
            return 'O Copiloto descartou uma alteração que não pode ser aplicada a este produto.';
        }
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
            'TARGET_ORDER_ITEM' => 'Falta identificar qual dos itens do pedido deve ser alterado.',
            'SALADA' => 'Falta escolher a salada.',
            'ADDRESS' => 'Falta informar o endereço.',
            'MENU_ITEM' => 'Falta identificar a marmita.',
            default => $label !== '' ? "Falta: {$label}." : 'Há uma informação pendente.',
        };
    }

    /** @param Collection<int,array<string,mixed>> $items @param \Illuminate\Support\Collection<int,Product> $products @param array<string,mixed> $context */
    private function orderByInboundReference($items, $products, array $context)
    {
        $positions = [];
        foreach (collect(data_get($context, 'messages', [])) as $messageIndex => $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $text = (string) ($message['body'] ?? '');
            foreach ($products as $product) {
                $pattern = match ($product->menu_rule_code) {
                    'n5_casa' => '/\\bn\\s*[- ]?\\s*5\\b/i',
                    'n8_casa', 'n8_tradicional' => '/\\bn\\s*[- ]?\\s*8\\b/i',
                    default => null,
                };
                if ($pattern === null || isset($positions[$product->id]) || preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $positions[$product->id] = ($messageIndex * 1_000_000) + $match[0][1];
            }
        }

        return $items->sort(function (array $left, array $right) use ($positions): int {
            $leftPosition = $positions[(int) ($left['menu_item_id'] ?? 0)] ?? PHP_INT_MAX;
            $rightPosition = $positions[(int) ($right['menu_item_id'] ?? 0)] ?? PHP_INT_MAX;

            return $leftPosition <=> $rightPosition;
        })->values();
    }

    /** @param list<array<string,mixed>> $warnings */
    private function warningRequiresHumanAction(array $warnings): bool
    {
        return collect($warnings)->contains(fn (array $warning): bool => in_array(
            strtoupper((string) ($warning['code'] ?? '')),
            ['CONFLICTING_MEAT_REQUEST', 'AMBIGUOUS_MEAT', 'UNRESOLVED_MEAT', 'UNRESOLVED_SELECTION'],
            true,
        ));
    }
}
