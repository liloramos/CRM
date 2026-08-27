<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotLatestMessageIntentResolver
{
    /** @param array<string,mixed> $context */
    public function resolve(array $context): string
    {
        $text = $this->latestInbound($context);

        if ($text === '') {
            return 'GENERAL_MESSAGE';
        }

        if (preg_match('/\b(cardapio|menu|o\s+que\s+tem\s+hoje|tem\s+hoje)\b/', $text) === 1) {
            return 'MENU_REQUEST';
        }

        if (preg_match('/\b(horario|horarios|funciona(?:ndo)?|aberto|fechado|atendendo)\b/', $text) === 1) {
            return 'BUSINESS_HOURS_REQUEST';
        }

        if (preg_match('/\b(chave\s+pix|pix\s+(?:para|de|do)|como\s+pagar\s+por\s+pix|qual(?:\s+e)?\s+(?:o|a)?\s*(?:chave\s+)?pix|me\s+passa\s+(?:a\s+)?(?:chave\s+)?pix)\b/', $text) === 1) {
            return 'PAYMENT_QUESTION';
        }

        if (preg_match('/\b(taxa|valor)\b.{0,24}\b(entrega|frete)\b|\b(entrega|frete)\b.{0,24}\b(taxa|valor)\b/', $text) === 1) {
            return 'DELIVERY_QUESTION';
        }

        if ($this->isProductInformationRequest($text)) {
            return 'PRODUCT_CLARIFICATION';
        }

        if (preg_match('/\b(troca|troque|alter[ae]|muda|mude|substitui[ar]?|tir[ae]|remove[ar]?)\b/', $text) === 1) {
            return 'ORDER_CHANGE';
        }

        if (count(data_get($context, 'messages', [])) > 1
            && preg_match('/\b(?:so|apenas|somente)\s+(?:uma|um)\b/', $text) === 1) {
            return 'ORDER_CHANGE';
        }

        if (preg_match('/\b(n\s*[- ]?\s*(?:5|8|9)|marmit(?:a|ex)|pedido|quero|queria|gostaria|me\s+ve|adiciona)\b/', $text) === 1) {
            return 'ORDER_CREATE';
        }

        if ($this->hasEligiblePendingClarification($context)) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/^(?:sim|isso(?:\s+mesmo)?|pode\s+ser|confere|confirmo|correto|ok(?:ay)?)\b/', $text) === 1) {
            return 'ORDER_CONFIRMATION';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/\b(pix|cart[aã]o|dinheiro|rua|avenida|av\.?|travessa|entrega|retirada|buscar|busca|endereco)\b/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/^(?:com\s+)?(?:frango|porco|almondega|bife)\b/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/^sem\s+\S+/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        if (preg_match('/\b(e\s+tambem|tambem|mais\s+uma|com\s+isso)\b/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        return 'GENERAL_MESSAGE';
    }

    private function isProductInformationRequest(string $text): bool
    {
        return preg_match('/\b(quanto\s+(?:custa|e)|qual\s+(?:o\s+)?(?:valor|preco)|(?:tem|vende|possui)\s+.+|o\s+que\s+vem)\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    private function latestInbound(array $context): string
    {
        foreach (array_reverse(data_get($context, 'messages', [])) as $message) {
            if (($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text') {
                return Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->squish()->toString();
            }
        }

        return '';
    }

    /** @param array<string,mixed> $context */
    private function hasCurrentPendingOrder(array $context): bool
    {
        $messages = data_get($context, 'messages', []);
        if (! is_array($messages)) {
            return false;
        }

        foreach (array_reverse(array_slice($messages, 0, -1)) as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $body = Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->squish()->toString();
            if (preg_match('/\bn\s*[- ]?\s*(?:5|8|9)\b/', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $context */
    private function hasEligiblePendingClarification(array $context): bool
    {
        return data_get($context, 'pending_clarification.status') === 'eligible'
            && in_array(data_get($context, 'pending_clarification.resolution.status'), ['resolved', 'ambiguous', 'invalid'], true);
    }
}
