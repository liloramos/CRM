<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * Single authority for deciding whether a Copilot result may leave analysis.
 *
 * The Copilot itself remains conservative and read-only. This policy is the
 * only place that can promote an already validated result to an outbound reply
 * or a draft-order staging action.
 */
final class CopilotAutomationAuthorityPolicy
{
    public const ROLLOUT_DISABLED = 'disabled';

    public const ROLLOUT_SHADOW = 'shadow';

    public const ROLLOUT_ACT_SAFE = 'act_safe';

    public const DECISION_DISABLED = 'disabled';

    public const DECISION_SHADOW = 'shadow';

    public const DECISION_AUTO_REPLY = 'auto_reply';

    public const DECISION_AUTO_ACTION = 'auto_action';

    public const DECISION_HUMAN_REVIEW = 'human_review';

    public const DECISION_DENIED_AUTO = 'denied_auto';

    /** @return array{rollout:string,decision:string,reason_codes:list<string>,action:?string,requires_human_review:bool} */
    public function decide(Conversation $conversation, array $analysis, string $rollout, bool $globalEnabled, string $inboundContent = ''): array
    {
        $rollout = $this->normalizeRollout($rollout);
        $intent = strtoupper((string) ($analysis['intent'] ?? 'UNKNOWN'));
        $proposal = is_array($analysis['proposal'] ?? null) ? $analysis['proposal'] : [];

        if ($conversation->automation_mode === Conversation::AUTOMATION_MODE_MANUAL) {
            return $this->decision($rollout, self::DECISION_HUMAN_REVIEW, ['conversation_manual_mode']);
        }

        if ($conversation->automation_mode !== Conversation::AUTOMATION_MODE_AUTOMATIC) {
            return $this->decision($rollout, self::DECISION_DISABLED, ['conversation_not_automatic']);
        }

        if (! $globalEnabled) {
            return $this->decision($rollout, self::DECISION_DISABLED, ['global_act_safe_disabled']);
        }

        if ($rollout === self::ROLLOUT_DISABLED) {
            return $this->decision($rollout, self::DECISION_DISABLED, ['company_rollout_disabled']);
        }

        if ($this->isFinancialOrAdministrative($intent, $analysis, $inboundContent)) {
            return $this->decision($rollout, self::DECISION_DENIED_AUTO, ['financial_or_administrative_action']);
        }

        if ($this->requiresHumanReview($intent, $analysis, $proposal)) {
            return $this->decision($rollout, self::DECISION_HUMAN_REVIEW, ['ambiguous_or_unsupported_request']);
        }

        $candidate = $this->candidate($intent, $analysis, $proposal);

        if ($rollout === self::ROLLOUT_SHADOW) {
            return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', ...$candidate['reason_codes']], $candidate['action']);
        }

        if ($candidate['action'] === null) {
            return $this->decision($rollout, self::DECISION_HUMAN_REVIEW, ['no_safe_action_candidate']);
        }

        return $this->decision($rollout, $candidate['decision'], $candidate['reason_codes'], $candidate['action']);
    }

    public function normalizeRollout(?string $rollout): string
    {
        $rollout = Str::of((string) $rollout)->lower()->trim()->toString();

        return in_array($rollout, [self::ROLLOUT_DISABLED, self::ROLLOUT_SHADOW, self::ROLLOUT_ACT_SAFE], true)
            ? $rollout
            : self::ROLLOUT_DISABLED;
    }

    /** @return array{decision:string,action:?string,reason_codes:list<string>} */
    private function candidate(string $intent, array $analysis, array $proposal): array
    {
        if (in_array($intent, ['MENU_REQUEST', 'BUSINESS_HOURS_REQUEST', 'PRODUCT_CLARIFICATION'], true)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['grounded_information_reply']];
        }

        if ($intent === 'ORDER_CREATE'
            && ($proposal['applyability'] ?? null) === 'READY'
            && ($proposal['target']['state'] ?? null) === 'NEW_ORDER'
            && ($proposal['target']['requires_human_selection'] ?? true) === false
            && ($proposal['items'] ?? []) !== []
            && empty($proposal['missing_information'])
            && empty($proposal['warnings'])
            && ! in_array(data_get($analysis, 'draft_order.fulfillment'), ['delivery'], true)) {
            return ['decision' => self::DECISION_AUTO_ACTION, 'action' => 'stage_new_order', 'reason_codes' => ['new_order_fully_validated']];
        }

        return ['decision' => self::DECISION_HUMAN_REVIEW, 'action' => null, 'reason_codes' => ['candidate_not_in_act_safe_v1']];
    }

    private function requiresHumanReview(string $intent, array $analysis, array $proposal): bool
    {
        if (in_array($intent, ['ORDER_CHANGE', 'ORDER_CONFIRMATION', 'ORDER_CONTINUE', 'DELIVERY_QUESTION', 'UNKNOWN'], true)) {
            return true;
        }

        if (! empty($analysis['missing_information']) || ! empty($analysis['warnings'])) {
            return true;
        }

        return ($proposal['target']['state'] ?? null) === 'UNRESOLVED';
    }

    private function isFinancialOrAdministrative(string $intent, array $analysis, string $inboundContent): bool
    {
        if (in_array($intent, [
            'PAYMENT_QUESTION',
            'PAYMENT_CONFIRM',
            'PAYMENT_CONFIRMATION',
            'PAYMENT_PROOF',
            'PAYMENT_VOID',
            'REFUND',
            'CANCEL_ORDER',
            'FINANCIAL_CANCELLATION',
            'DELIVERY_FEE_CHANGE',
            'GLOBAL_CONFIGURATION',
            'HARD_DELETE',
        ], true)) {
            return true;
        }

        $text = Str::of(implode(' ', [
            (string) ($analysis['summary'] ?? ''),
            (string) ($analysis['suggested_reply'] ?? ''),
            (string) data_get($analysis, 'draft_order.payment_method', ''),
            $inboundContent,
        ]))->ascii()->lower()->toString();

        return Str::contains($text, [
            'pagamento confirmado', 'comprovante', 'reembolso', 'estorno', 'cancelamento financeiro',
            'anular pagamento', 'financeiro', 'pix', 'credito', 'taxa manual', 'taxa de entrega',
            'configuracao global', 'alterar configuracao', 'administrador', 'hard delete',
            'excluir permanentemente', 'apagar permanentemente',
        ]);
    }

    /** @param list<string> $reasonCodes @return array{rollout:string,decision:string,reason_codes:list<string>,action:?string,requires_human_review:bool} */
    private function decision(string $rollout, string $decision, array $reasonCodes, ?string $action = null): array
    {
        return [
            'rollout' => $rollout,
            'decision' => $decision,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'action' => $action,
            'requires_human_review' => in_array($decision, [self::DECISION_HUMAN_REVIEW, self::DECISION_DENIED_AUTO], true),
        ];
    }
}
