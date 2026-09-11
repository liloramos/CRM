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
    public function __construct(private readonly CopilotReviewClassification $reviewClassification) {}

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
        return $this->decideForState(
            (string) $conversation->automation_mode,
            (bool) $conversation->human_review_required,
            $analysis,
            $rollout,
            $globalEnabled,
            $inboundContent,
        );
    }

    /** @return array{rollout:string,decision:string,reason_codes:list<string>,action:?string,requires_human_review:bool} */
    public function decideForSandbox(array $analysis, string $rollout, bool $globalEnabled, string $inboundContent = ''): array
    {
        return $this->decideForState(
            Conversation::AUTOMATION_MODE_AUTOMATIC,
            false,
            $analysis,
            $rollout,
            $globalEnabled,
            $inboundContent,
        );
    }

    /** @return array{rollout:string,decision:string,reason_codes:list<string>,action:?string,requires_human_review:bool} */
    private function decideForState(string $automationMode, bool $humanReviewRequired, array $analysis, string $rollout, bool $globalEnabled, string $inboundContent): array
    {
        $rollout = $this->normalizeRollout($rollout);
        $intent = strtoupper((string) ($analysis['intent'] ?? 'UNKNOWN'));
        $proposal = is_array($analysis['proposal'] ?? null) ? $analysis['proposal'] : [];

        if ($automationMode === Conversation::AUTOMATION_MODE_MANUAL) {
            return $this->decision($rollout, self::DECISION_HUMAN_REVIEW, ['conversation_manual_mode']);
        }

        if ($automationMode !== Conversation::AUTOMATION_MODE_AUTOMATIC) {
            return $this->decision($rollout, self::DECISION_DISABLED, ['conversation_not_automatic']);
        }

        if (! $globalEnabled) {
            return $this->decision($rollout, self::DECISION_DISABLED, ['global_act_safe_disabled']);
        }

        if ($rollout === self::ROLLOUT_DISABLED) {
            return $this->decision($rollout, self::DECISION_DISABLED, ['company_rollout_disabled']);
        }

        if ($this->isExplicitHandoff($intent, $analysis)) {
            $reason = (string) data_get($analysis, 'metadata.handoff_reason', 'operational_exception');

            return $this->decision($rollout, self::DECISION_HUMAN_REVIEW, ['handoff_'.$reason], 'send_handoff_reply');
        }

        if ($this->isFinancialOrAdministrative($intent, $analysis, $inboundContent)) {
            return $this->decision(
                $rollout,
                self::DECISION_DENIED_AUTO,
                ['financial_or_administrative_action'],
                $intent === 'PAYMENT_CONFIRMATION' && data_get($analysis, 'metadata.payment_confirmation_requires_human') === true
                    ? 'send_payment_confirmation_notice'
                    : null,
            );
        }

        if ($this->isShadowSafeClarification($analysis)) {
            if ($rollout === self::ROLLOUT_SHADOW) {
                return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', 'safe_clarification_available'], 'send_safe_clarification');
            }

            return $this->decision($rollout, self::DECISION_AUTO_REPLY, ['grounded_safe_clarification'], 'send_safe_clarification');
        }

        if ($this->isReadyForDeliveryQuote($analysis, $proposal)) {
            if ($rollout === self::ROLLOUT_SHADOW) {
                return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', 'payment_method_required_after_quote'], 'stage_new_order');
            }

            return $this->decision($rollout, self::DECISION_AUTO_ACTION, ['payment_method_required_after_quote'], 'stage_new_order');
        }

        if ($this->isNormalCustomerClarification($intent, $analysis)) {
            $action = data_get($analysis, 'metadata.reply_source') === 'order_clarification'
                ? 'send_order_clarification'
                : 'send_grounded_reply';
            $reason = data_get($analysis, 'metadata.recoverable_unknown')
                ? 'recoverable_unknown_clarification'
                : 'normal_missing_information';

            if ($rollout === self::ROLLOUT_SHADOW) {
                return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', $reason], $action);
            }

            return $this->decision($rollout, self::DECISION_AUTO_REPLY, [$reason], $action);
        }

        if ($this->isGroundedInformationReply($intent, $analysis)) {
            if ($rollout === self::ROLLOUT_SHADOW) {
                return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', 'grounded_information_reply'], 'send_grounded_reply');
            }

            return $this->decision($rollout, self::DECISION_AUTO_REPLY, ['grounded_information_reply'], 'send_grounded_reply');
        }

        if ($intent === 'ORDER_CONTINUE'
            && data_get($analysis, 'metadata.reply_source') === 'payment_selection'
            && (int) data_get($analysis, 'metadata.active_order_id') > 0
            && data_get($analysis, 'draft_order.payment_method') === 'pix') {
            if ($rollout === self::ROLLOUT_SHADOW) {
                return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', 'active_order_payment_selected'], 'prepare_order_payment');
            }

            return $this->decision($rollout, self::DECISION_AUTO_ACTION, ['active_order_payment_selected'], 'prepare_order_payment');
        }

        if ($this->isTurnLoopReply($intent, $analysis)) {
            $objective = (string) data_get($analysis, 'metadata.order_context.next_objective');
            $reason = match ($objective) {
                'CONFIRM_ITEM' => 'item_confirmation_required',
                'ASK_MORE_ITEMS' => 'additional_items_confirmation_required',
                'ASK_FULFILLMENT' => 'fulfillment_required',
                'ASK_LOCATION' => 'location_required',
                'ASK_PAYMENT_METHOD' => 'payment_method_required',
                'WAIT_PAYMENT_PROOF' => 'payment_proof_required',
                default => 'turn_loop_next_objective',
            };

            if ($rollout === self::ROLLOUT_SHADOW) {
                return $this->decision($rollout, self::DECISION_SHADOW, ['shadow_no_execution', $reason], 'send_grounded_reply');
            }

            return $this->decision($rollout, self::DECISION_AUTO_REPLY, [$reason], 'send_grounded_reply');
        }

        if ($this->requiresHumanReview($intent, $analysis, $proposal)) {
            return $this->decision(
                $rollout,
                self::DECISION_HUMAN_REVIEW,
                [$intent === 'ORDER_CONTINUE' ? 'order_continue_requires_human_review' : 'ambiguous_or_unsupported_request'],
            );
        }

        $candidate = $this->candidate($intent, $analysis, $proposal);

        if ($rollout === self::ROLLOUT_ACT_SAFE
            && $humanReviewRequired
            && $candidate['decision'] === self::DECISION_AUTO_ACTION) {
            return $this->decision($rollout, self::DECISION_HUMAN_REVIEW, ['pending_human_review_blocks_mutation']);
        }

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
        $objective = (string) data_get($analysis, 'metadata.order_context.next_objective', '');
        $hasCanonicalTurnState = is_array(data_get($analysis, 'metadata.order_context'));
        if (in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE'], true)
            && data_get($analysis, 'metadata.operational_status') === 'CLOSED'
            && $this->hasNoOperationalPayload($analysis)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['restaurant_closed']];
        }

        if ($intent === 'ORDER_CREATE'
            && data_get($analysis, 'metadata.reply_source') === 'order_start'
            && $this->hasNoOperationalPayload($analysis)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['safe_order_start_reply']];
        }

        if (in_array($intent, ['GREETING', 'GENERAL_MESSAGE', 'GENERAL_QUESTION'], true)
            && $this->hasNoOperationalPayload($analysis)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['safe_conversational_reply']];
        }

        if (in_array($intent, ['MENU_REQUEST', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST', 'PRODUCT_CLARIFICATION'], true)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['grounded_information_reply']];
        }

        if (in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CHANGE', 'ORDER_CONFIRMATION'], true)
            && $this->isReadyNewOrder($analysis, $proposal)
            && blank(data_get($analysis, 'draft_order.fulfillment'))
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['fulfillment_required']];
        }

        if ($intent === 'ORDER_CONTINUE'
            && $this->isResolvedClarificationContinuation($analysis)
            && ($proposal['applyability'] ?? null) === 'READY'
            && ($proposal['items'] ?? []) !== []
            && empty($proposal['missing_information'])
            && $this->hasOnlyRecoverableWarnings($analysis)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '') {
            return ['decision' => self::DECISION_AUTO_REPLY, 'action' => 'send_grounded_reply', 'reason_codes' => ['resolved_order_clarification']];
        }

        if (in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CHANGE', 'ORDER_CONFIRMATION'], true)
            && $this->isReadyNewOrder($analysis, $proposal)
            && data_get($analysis, 'draft_order.fulfillment') === 'pickup'
            && (! $hasCanonicalTurnState || $objective === 'ASK_PAYMENT_METHOD')) {
            return ['decision' => self::DECISION_AUTO_ACTION, 'action' => 'stage_new_order', 'reason_codes' => ['new_order_fully_validated']];
        }

        if (in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CHANGE', 'ORDER_CONFIRMATION'], true)
            && $this->isReadyNewOrder($analysis, $proposal)
            && data_get($analysis, 'draft_order.fulfillment') === 'delivery'
            && filled(data_get($analysis, 'draft_order.address'))
            && filled(data_get($analysis, 'draft_order.payment_method'))
            && (! $hasCanonicalTurnState || $objective === 'CALCULATE_DELIVERY')) {
            return ['decision' => self::DECISION_AUTO_ACTION, 'action' => 'stage_new_order', 'reason_codes' => ['new_delivery_order_fully_validated']];
        }

        return ['decision' => self::DECISION_HUMAN_REVIEW, 'action' => null, 'reason_codes' => ['candidate_not_in_act_safe_v1']];
    }

    /** @param array<string,mixed> $analysis */
    private function hasNoOperationalPayload(array $analysis): bool
    {
        return data_get($analysis, 'draft_order.items', []) === []
            && blank(data_get($analysis, 'draft_order.fulfillment'))
            && blank(data_get($analysis, 'draft_order.address'))
            && blank(data_get($analysis, 'draft_order.payment_method'));
    }

    private function requiresHumanReview(string $intent, array $analysis, array $proposal): bool
    {
        if (in_array($intent, ['DELIVERY_QUESTION', 'HUMAN_REQUEST', 'UNKNOWN'], true)) {
            return true;
        }

        if ($intent === 'ORDER_CHANGE' && ! is_array(data_get($analysis, 'metadata.order_context'))) {
            return true;
        }

        $missing = $this->codes((array) ($analysis['missing_information'] ?? []));
        $warnings = $this->codes((array) ($analysis['warnings'] ?? []));
        if ((! empty($analysis['missing_information']) || ! empty($analysis['warnings']))
            && trim((string) ($analysis['suggested_reply'] ?? '')) === '') {
            return true;
        }
        if (! $this->reviewClassification->onlyOrdinaryMissing($missing)
            || ! $this->reviewClassification->onlyRecoverableWarnings($warnings)) {
            return true;
        }

        if (($missing !== [] || $warnings !== [])
            && filled(data_get($analysis, 'metadata.order_context.next_objective'))
            && data_get($analysis, 'metadata.final_reply_composer') === 'post_reducer_turn_loop') {
            return false;
        }

        return in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)
            && ($proposal['target']['state'] ?? null) === 'UNRESOLVED';
    }

    private function isExplicitHandoff(string $intent, array $analysis): bool
    {
        return $intent === 'HUMAN_REQUEST'
            && data_get($analysis, 'metadata.reply_source') === 'explicit_handoff'
            && filled(data_get($analysis, 'metadata.handoff_reason'))
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '';
    }

    private function isNormalCustomerClarification(string $intent, array $analysis): bool
    {
        $replySource = (string) data_get($analysis, 'metadata.reply_source', '');
        if ($intent === 'UNKNOWN') {
            $objective = (string) data_get($analysis, 'metadata.order_context.next_objective', '');
            if (in_array($objective, [
                'ASK_PRODUCT', 'ASK_MEAT', 'ASK_SALAD', 'ASK_COMPONENTS', 'ASK_QUANTITY', 'ASK_FLAVOR',
                'CONFIRM_ITEM', 'ASK_MORE_ITEMS', 'ASK_FULFILLMENT', 'ASK_LOCATION', 'ASK_PAYMENT_METHOD',
                'WAIT_PAYMENT_PROOF',
            ], true) && data_get($analysis, 'metadata.final_reply_composer') === 'post_reducer_turn_loop') {
                $warnings = $this->codes((array) ($analysis['warnings'] ?? []));

                return $this->reviewClassification->onlyRecoverableWarnings($warnings);
            }

            return $replySource === 'recovery_clarification'
                && data_get($analysis, 'metadata.recoverable_unknown') === true
                && trim((string) ($analysis['suggested_reply'] ?? '')) !== '';
        }

        if (! in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE'], true)
            || trim((string) ($analysis['suggested_reply'] ?? '')) === '') {
            return false;
        }

        $missing = $this->codes((array) ($analysis['missing_information'] ?? []));
        $warnings = $this->codes((array) ($analysis['warnings'] ?? []));

        return $this->reviewClassification->onlyOrdinaryMissing($missing)
            && $this->reviewClassification->onlyRecoverableWarnings($warnings)
            && ($missing !== [] || $warnings !== [] || $replySource === 'order_clarification');
    }

    private function isGroundedInformationReply(string $intent, array $analysis): bool
    {
        return in_array($intent, ['MENU_REQUEST', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST', 'PRODUCT_CLARIFICATION', 'PAYMENT_QUESTION', 'DELIVERY_QUESTION', 'ORDER_STATUS'], true)
            && in_array((string) data_get($analysis, 'metadata.reply_source'), ['daily_menu', 'product_catalog', 'operating_hours', 'operating_hours_unconfigured', 'customer_facing_policy', 'semantic_grounded_product_information', 'semantic_grounded_comparison', 'semantic_grounded_options'], true)
            && trim((string) ($analysis['suggested_reply'] ?? '')) !== '';
    }

    private function isShadowSafeClarification(array $analysis): bool
    {
        $clarification = $analysis['clarification'] ?? null;
        $options = (array) data_get($clarification, 'options', []);
        $optionIds = array_values(array_unique(array_filter(array_map(
            fn (mixed $option): int => (int) data_get($option, 'component_id'),
            $options,
        ))));
        $warningCodes = $this->codes((array) ($analysis['warnings'] ?? []));
        $missingCodes = $this->codes((array) ($analysis['missing_information'] ?? []));

        return is_array($clarification)
            && (string) ($analysis['intent'] ?? '') === 'ORDER_CREATE'
            && ($clarification['type'] ?? null) === 'MEAT'
            && in_array(($clarification['source'] ?? null), ['DAILY_MENU', 'PRODUCT_CONFIGURATION'], true)
            && ($clarification['grounded'] ?? false) === true
            && (string) data_get($clarification, 'scope.selection_group') === 'meat'
            && (int) data_get($clarification, 'scope.product_id') > 0
            && count($options) >= 2
            && count($options) <= 5
            && count($optionIds) === count($options)
            && array_diff($warningCodes, ['AMBIGUOUS_MEAT', 'DOMAIN_SELECTION_REJECTED']) === []
            && array_diff($missingCodes, ['CARNE']) === [];
    }

    /** @param array<string,mixed> $analysis */
    private function isResolvedClarificationContinuation(array $analysis): bool
    {
        return data_get($analysis, 'metadata.clarification_continuity.resolution') === 'resolved'
            && (int) data_get($analysis, 'metadata.clarification_continuity.source_event_id') > 0
            && (int) data_get($analysis, 'metadata.clarification_continuity.matched_option_id') > 0;
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed> $proposal */
    private function isReadyNewOrder(array $analysis, array $proposal): bool
    {
        return ($proposal['applyability'] ?? null) === 'READY'
            && ($proposal['target']['state'] ?? null) === 'NEW_ORDER'
            && ($proposal['target']['requires_human_selection'] ?? true) === false
            && ($proposal['items'] ?? []) !== []
            && empty($proposal['missing_information'])
            && empty($proposal['warnings'])
            && collect((array) data_get($analysis, 'draft_order.items', []))
                ->every(fn (mixed $item): bool => is_array($item) && ($item['valid'] ?? false) === true);
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed> $proposal */
    private function isReadyForDeliveryQuote(array $analysis, array $proposal): bool
    {
        $missing = $this->codes((array) ($analysis['missing_information'] ?? []));

        if (data_get($analysis, 'metadata.order_context.next_objective') === 'CALCULATE_DELIVERY') {
            return ($proposal['target']['state'] ?? null) === 'NEW_ORDER'
                && ($proposal['items'] ?? []) !== []
                && $this->hasOnlyNonBlockingQuoteWarnings($analysis)
                && data_get($analysis, 'draft_order.fulfillment') === 'delivery'
                && filled(data_get($analysis, 'draft_order.address'))
                && collect((array) data_get($analysis, 'draft_order.items', []))
                    ->every(fn (mixed $item): bool => is_array($item) && ($item['valid'] ?? false) === true);
        }

        return ($proposal['applyability'] ?? null) === 'PARTIAL'
            && ($proposal['target']['state'] ?? null) === 'NEW_ORDER'
            && ($proposal['target']['requires_human_selection'] ?? true) === false
            && ($proposal['items'] ?? []) !== []
            && $missing === ['PAYMENT_METHOD']
            && $this->hasOnlyRecoverableWarnings($analysis)
            && data_get($analysis, 'draft_order.fulfillment') === 'delivery'
            && filled(data_get($analysis, 'draft_order.address'))
            && collect((array) data_get($analysis, 'draft_order.items', []))
                ->every(fn (mixed $item): bool => is_array($item) && ($item['valid'] ?? false) === true);
    }

    /** @param array<string,mixed> $analysis */
    private function isTurnLoopReply(string $intent, array $analysis): bool
    {
        $objective = (string) data_get($analysis, 'metadata.order_context.next_objective', '');
        if (! in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CHANGE', 'ORDER_CONFIRMATION'], true)
            || ! in_array($objective, [
                'ASK_PRODUCT', 'ASK_MEAT', 'ASK_SALAD', 'ASK_COMPONENTS', 'ASK_QUANTITY', 'ASK_FLAVOR',
                'CONFIRM_ITEM', 'ASK_MORE_ITEMS', 'ASK_FULFILLMENT', 'ASK_LOCATION', 'ASK_PAYMENT_METHOD',
                'WAIT_PAYMENT_PROOF', 'RESOLVE_CONSTRAINT',
            ], true)
            || trim((string) ($analysis['suggested_reply'] ?? '')) === '') {
            return false;
        }

        return $this->reviewClassification->onlyRecoverableWarnings($this->codes((array) ($analysis['warnings'] ?? [])));
    }

    /** @param array<string,mixed> $analysis */
    private function hasOnlyRecoverableWarnings(array $analysis): bool
    {
        $codes = $this->codes((array) ($analysis['warnings'] ?? []));

        return $this->reviewClassification->onlyRecoverableWarnings($codes);
    }

    /** @param array<string,mixed> $analysis */
    private function hasOnlyNonBlockingQuoteWarnings(array $analysis): bool
    {
        return $this->reviewClassification->onlyRecoverableWarnings($this->codes((array) ($analysis['warnings'] ?? [])));
    }

    /** @param list<array<string,mixed>> $entries @return list<string> */
    private function codes(array $entries): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $entry): string => strtoupper(trim((string) (is_array($entry) ? data_get($entry, 'code', '') : $entry))),
            $entries,
        ))));
    }

    private function isFinancialOrAdministrative(string $intent, array $analysis, string $inboundContent): bool
    {
        if ($intent === 'PAYMENT_QUESTION'
            && data_get($analysis, 'metadata.reply_source') === 'customer_facing_policy') {
            return false;
        }

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

        if (preg_match('/\b(?:paguei|ja\s+paguei)\b.{0,36}\b(?:pix|cartao|dinheiro)\b|\bconfirm(?:a|ar|e)\b.{0,24}\bpagamento\b|\bpagamento\b.{0,24}\bconfirm(?:a|ar|e)\b/', $text) === 1) {
            return true;
        }

        if (in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE'], true)
            && mb_strtolower(trim((string) data_get($analysis, 'draft_order.payment_method', ''))) === 'pix'
            && array_key_exists('pix_configured', (array) ($analysis['metadata'] ?? []))) {
            return false;
        }

        return Str::contains($text, [
            'pagamento confirmado', 'comprovante', 'reembolso', 'estorno', 'cancelamento financeiro',
            'anular pagamento', 'financeiro', 'taxa manual', 'alterar taxa de entrega',
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
