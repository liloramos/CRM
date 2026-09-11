<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotIntentGroundingGuard
{
    /** @param list<array{code:string,label:string}> $missing @param list<array<string,mixed>> $items @param array<string,mixed> $context @return list<array{code:string,label:string}> */
    public function refineMissing(array $missing, array $items, array $context): array
    {
        $latest = $this->latestInboundText($context);
        if ($items !== []
            || ! $this->hasPurchaseCue($latest)
            || ! $this->hasIncompleteMenuReference($latest)
            || ! $this->mentionsMeat($latest)) {
            return $missing;
        }

        return array_map(function (array $item): array {
            if (strtoupper((string) ($item['code'] ?? '')) !== 'PRODUCT') {
                return $item;
            }

            return [...$item, 'code' => 'MENU_ITEM', 'label' => 'Produto'];
        }, $missing);
    }

    /** @param list<array<string,mixed>> $items @param list<array{code:string,label:string}> $missing @param list<array<string,mixed>> $warnings @param array<string,mixed> $context */
    public function finalize(string $proposed, array $items, array $missing, array $warnings, array $context): string
    {
        $messages = collect(data_get($context, 'messages', []))
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->values();
        $latest = $this->latestInboundText($context);
        $missingCodes = array_column($missing, 'code');
        $warningCodes = array_column($warnings, 'code');

        if ($this->isUntrustedInstruction($latest) || in_array('UNTRUSTED_INSTRUCTION', $warningCodes, true)) {
            return 'UNKNOWN';
        }

        if ($this->isPreviousOrderReference($latest) && ! (bool) data_get($context, 'previous_order_context.available', false)) {
            return 'UNKNOWN';
        }

        if ($messages->count() > 1
            && preg_match('/\b(entrega|retirada|buscar|busca|endereco)\b/', $latest) === 1
            && preg_match('/\bn\s*[- ]?\s*\d+\b/', $latest) !== 1) {
            return 'ORDER_CHANGE';
        }

        if (in_array('INVALID_QUANTITY', $warningCodes, true) && $this->hasPurchaseCue($latest)) {
            return 'ORDER_CREATE';
        }

        if ($items === [] && in_array('MENU_ITEM', $missingCodes, true) && $this->hasPurchaseCue($latest) && $this->mentionsMeat($latest)) {
            return 'ORDER_CREATE';
        }

        if ($items === [] && in_array('PRODUCT', $missingCodes, true)) {
            return $this->hasPurchaseCue($latest)
                && in_array((string) data_get($context, 'latest_intent'), ['ORDER_CREATE', 'ORDER_CONTINUE'], true)
                ? (string) data_get($context, 'latest_intent')
                : 'UNKNOWN';
        }

        return $proposed;
    }

    private function hasPurchaseCue(string $text): bool
    {
        return preg_match('/\b(quero|manda|mandar|me\s+da|me\s+ve)\b/', $text) === 1;
    }

    private function mentionsMeat(string $text): bool
    {
        return preg_match('/\bcarne\b/', $text) === 1;
    }

    private function hasIncompleteMenuReference(string $text): bool
    {
        return preg_match('/\ba\s+de\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    private function latestInboundText(array $context): string
    {
        $messages = collect(data_get($context, 'messages', []))
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->values();

        return $this->key((string) data_get($messages, ($messages->count() - 1).'.body', ''));
    }

    private function isPreviousOrderReference(string $text): bool
    {
        return preg_match('/\b(aquela|aquele)\b.*\b(ontem|anterior)\b|\bde\s+ontem\b/', $text) === 1;
    }

    private function isUntrustedInstruction(string $text): bool
    {
        return preg_match('/\b(ignore|ignora)\b.*\b(regra|regras|instrucao|instrucoes)\b/', $text) === 1;
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->toString();
    }
}
