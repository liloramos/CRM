<?php

namespace App\Services\Ai;

final class CopilotAutomationEvaluationDataset
{
    public const VERSION = 1;

    public static function fingerprint(): string
    {
        return hash('sha256', json_encode(self::cases(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array<string,mixed>> */
    public static function cases(): array
    {
        return [
            self::information('MENU_REQUEST', 'cardapio', CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY),
            self::information('BUSINESS_HOURS_REQUEST', 'horario', CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY),
            self::information('PRODUCT_CLARIFICATION', 'produto', CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY),
            self::information('PRODUCT_CLARIFICATION', 'preco_n8', CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY),
            self::information('PRODUCT_CLARIFICATION', 'disponibilidade_suco_laranja', CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY),
            self::readyOrder('n5_validada'),
            self::readyOrder('n8_frango_e_porco'),
            self::readyOrder('novo_pedido_sem_remocao_historica'),
            self::readyOrder('n8_sem_carne'),
            self::readyOrder('n8_somente_bife'),
            self::readyOrder('n8_bife_adicional'),
            self::readyOrder('n9_validada'),
            self::humanReview('multi_item_incompleto', 'ORDER_CREATE', ['CARNE']),
            self::humanReview('pedido_ativo', 'ORDER_CREATE', [], 'EXISTING_ORDER'),
            self::humanReview('ORDER_CHANGE', 'ORDER_CHANGE'),
            self::humanReview('produto_ambiguo', 'UNKNOWN', ['MENU_ITEM']),
            self::humanReview('PRICE_MISMATCH', 'ORDER_CREATE', [], 'NEW_ORDER', ['PRICE_MISMATCH']),
            self::denied('pix', 'PAYMENT_QUESTION'),
            self::denied('comprovante', 'PAYMENT_PROOF'),
            self::denied('PAYMENT_CONFIRM', 'PAYMENT_CONFIRMATION'),
            self::denied('PAYMENT_VOID', 'PAYMENT_VOID'),
            self::denied('refund', 'REFUND'),
            self::denied('cancelamento_financeiro', 'FINANCIAL_CANCELLATION'),
            self::denied('taxa_manual_delivery', 'DELIVERY_FEE_CHANGE'),
            self::denied('configuracao_global', 'GLOBAL_CONFIGURATION'),
            self::denied('hard_delete', 'HARD_DELETE'),
            self::humanReview('delivery_quote_existente', 'DELIVERY_QUESTION'),
            self::humanReview('delivery_quote_ausente', 'DELIVERY_QUESTION', ['DELIVERY_QUOTE']),
            self::humanReview('endereco_ambiguo', 'ORDER_CREATE', ['ADDRESS']),
            self::humanReview('route_failure', 'DELIVERY_QUESTION', ['ROUTE_UNAVAILABLE']),
            self::humanReview('malformed_provider_output', 'UNKNOWN', [], 'UNRESOLVED', ['INVALID_PROVIDER_OUTPUT']),
        ];
    }

    /** @return array<string,mixed> */
    private static function information(string $intent, string $id, string $expected): array
    {
        return self::case($id, $intent, $expected, [
            'suggested_reply' => 'Resposta operacional fundamentada.',
        ]);
    }

    /** @return array<string,mixed> */
    private static function readyOrder(string $id): array
    {
        return self::case($id, 'ORDER_CREATE', CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION, [
            'suggested_reply' => 'Resumo do pedido para confirmação humana.',
            'draft_order' => ['fulfillment' => 'pickup'],
            'proposal' => [
                'applyability' => 'READY',
                'items' => [['valid' => true]],
                'target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private static function humanReview(string $id, string $intent, array $missing = [], string $target = 'NEW_ORDER', array $warnings = []): array
    {
        return self::case($id, $intent, CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, [
            'missing_information' => $missing,
            'warnings' => $warnings,
            'proposal' => ['target' => ['state' => $target, 'requires_human_selection' => $target !== 'NEW_ORDER']],
        ], $target === 'EXISTING_ORDER' && $missing === [] && $warnings === []
            ? CopilotAutomationAuthorityPolicy::DECISION_SHADOW
            : null);
    }

    /** @return array<string,mixed> */
    private static function denied(string $id, string $intent): array
    {
        return self::case($id, $intent, CopilotAutomationAuthorityPolicy::DECISION_DENIED_AUTO);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function case(string $id, string $intent, string $expected, array $overrides = [], ?string $shadowExpected = null): array
    {
        return [
            'id' => $id,
            'intent' => $intent,
            'inbound_content' => $id,
            'expected_act_safe' => $expected,
            'expected_shadow' => $shadowExpected ?? (in_array($expected, [
                CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY,
                CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION,
            ], true) ? CopilotAutomationAuthorityPolicy::DECISION_SHADOW : $expected),
            'analysis' => [
                'intent' => $intent,
                'suggested_reply' => '',
                'draft_order' => ['fulfillment' => null],
                'missing_information' => [],
                'warnings' => [],
                'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
                ...$overrides,
            ],
        ];
    }
}
