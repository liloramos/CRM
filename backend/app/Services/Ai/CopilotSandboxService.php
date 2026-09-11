<?php

namespace App\Services\Ai;

use App\Models\Company;

final class CopilotSandboxService
{
    public function __construct(
        private readonly ConversationCopilotService $copilot,
        private readonly CopilotAutomationAuthorityPolicy $authority,
        private readonly CopilotAutomationSettings $settings,
    ) {}

    /** @return array{reply:string,reply_messages:list<string>,classification:string,requires_human_review:bool,action:string,reason:string,rollout:string} */
    public function simulate(Company $company, string $message): array
    {
        $analysis = $this->copilot->analyzeMessages($company, [[
            'direction' => 'inbound',
            'type' => 'text',
            'body' => $message,
        ]]);
        $rollout = $this->settings->rolloutFor($company);
        $decision = $this->authority->decideForSandbox(
            $analysis,
            $rollout,
            $this->settings->globallyEnabled(),
            $message,
        );
        $providerFailed = collect((array) ($analysis['warnings'] ?? []))
            ->contains(fn (mixed $warning): bool => in_array(data_get($warning, 'code'), ['PROVIDER_UNAVAILABLE', 'INVALID_PROVIDER_OUTPUT'], true));

        if ($providerFailed) {
            return [
                'reply' => 'Não foi possível simular a IA agora.',
                'reply_messages' => ['Não foi possível simular a IA agora.'],
                'classification' => 'Simulação indisponível',
                'requires_human_review' => true,
                'action' => 'Nenhuma ação executada',
                'reason' => 'O provider não respondeu de forma segura.',
                'rollout' => $rollout,
            ];
        }

        $replyMessages = collect((array) ($analysis['reply_messages'] ?? []))
            ->filter(fn (mixed $reply): bool => is_string($reply) && trim($reply) !== '')
            ->map(fn (string $reply): string => trim($reply))
            ->take(3)
            ->values();
        if ($replyMessages->isEmpty() && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            $replyMessages->push(trim((string) $analysis['suggested_reply']));
        }
        if ($replyMessages->isEmpty()) {
            $replyMessages->push('A simulação não produziu uma resposta ao cliente.');
        }

        return [
            'reply' => $replyMessages->implode("\n\n"),
            'reply_messages' => $replyMessages->all(),
            'classification' => $this->classification((string) ($analysis['intent'] ?? 'UNKNOWN')),
            'requires_human_review' => in_array($decision['decision'], [
                CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW,
                CopilotAutomationAuthorityPolicy::DECISION_DENIED_AUTO,
            ], true),
            'action' => $this->action($decision),
            'reason' => $this->reason($decision),
            'rollout' => $rollout,
        ];
    }

    private function classification(string $intent): string
    {
        return match ($intent) {
            'ORDER_CREATE', 'ORDER_CONTINUE' => 'Intenção de pedido',
            'ORDER_CHANGE', 'ORDER_CONFIRMATION' => 'Continuação de pedido',
            'MENU_REQUEST', 'PRODUCT_CLARIFICATION' => 'Consulta de cardápio',
            'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST', 'PAYMENT_QUESTION', 'DELIVERY_QUESTION' => 'Informação geral',
            'HUMAN_REQUEST' => 'Atendimento humano',
            'GREETING', 'GENERAL_MESSAGE', 'GENERAL_QUESTION' => 'Conversa geral',
            default => 'Esclarecimento',
        };
    }

    /** @param array<string,mixed> $decision */
    private function action(array $decision): string
    {
        return match ($decision['decision'] ?? null) {
            CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY => 'Resposta segura ao cliente',
            CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION => 'Montagem segura de pedido',
            CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW => 'Handoff para atendimento humano',
            CopilotAutomationAuthorityPolicy::DECISION_DENIED_AUTO => 'Ação protegida bloqueada',
            CopilotAutomationAuthorityPolicy::DECISION_SHADOW => 'Somente observação em Shadow',
            default => 'Nenhuma ação executada',
        };
    }

    /** @param array<string,mixed> $decision */
    private function reason(array $decision): string
    {
        $codes = (array) ($decision['reason_codes'] ?? []);

        if (collect($codes)->contains(fn (mixed $code): bool => str_starts_with((string) $code, 'handoff_'))) {
            return 'A solicitação exige participação da equipe.';
        }
        if (in_array('financial_or_administrative_action', $codes, true)) {
            return 'Os limites financeiros e administrativos bloquearam a automação.';
        }
        if (in_array('company_rollout_disabled', $codes, true) || in_array('global_act_safe_disabled', $codes, true)) {
            return 'A automação está desativada; nenhum efeito seria executado.';
        }
        if (in_array('shadow_no_execution', $codes, true)) {
            return 'O modo Shadow apenas observa e não executa efeitos.';
        }

        return 'Resultado avaliado pelos mesmos limites de autoridade do Copilot.';
    }
}
