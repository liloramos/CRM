<?php

namespace App\Services\Ai;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes the small, explicitly allowed Level 2 surface after the read-only
 * Copilot has produced a validated result. It intentionally has no payment,
 * delivery, cancellation, or configuration capabilities.
 */
final class CopilotAutomationService
{
    public function __construct(
        private readonly ConversationCopilotService $copilot,
        private readonly CopilotAutomationAuthorityPolicy $policy,
        private readonly CopilotAutomationSettings $settings,
        private readonly CopilotProductEligibility $eligibility,
        private readonly OrderWorkflowService $orders,
        private readonly WhatsAppService $whatsapp,
        private readonly ConversationAlertService $alerts,
    ) {}

    public function handle(int $messageId, int $expectedAutomationVersion): ?AutomationEvent
    {
        return DB::transaction(function () use ($messageId, $expectedAutomationVersion): ?AutomationEvent {
            $message = Message::query()
                ->with('conversation.company')
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if (! $message instanceof Message || $message->direction !== 'inbound') {
                return null;
            }

            $conversation = Conversation::query()
                ->whereKey($message->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $company = $conversation->company()->firstOrFail();

            $existing = AutomationEvent::query()
                ->where('message_id', $message->id)
                ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof AutomationEvent) {
                return $existing;
            }

            if (! $this->isCurrentInboundMessage($conversation, $message)) {
                return $this->record($company, $conversation, $message, [
                    'rollout' => $this->settings->rolloutFor($company),
                    'decision' => CopilotAutomationAuthorityPolicy::DECISION_DISABLED,
                    'reason_codes' => ['stale_inbound_message'],
                    'action' => null,
                    'requires_human_review' => false,
                ]);
            }

            if ((int) $conversation->automation_version !== $expectedAutomationVersion) {
                return $this->record($company, $conversation, $message, [
                    'rollout' => $this->settings->rolloutFor($company),
                    'decision' => CopilotAutomationAuthorityPolicy::DECISION_DISABLED,
                    'reason_codes' => ['automation_version_changed'],
                    'action' => null,
                    'requires_human_review' => false,
                ]);
            }

            // These gates are intentionally evaluated before the Copilot analysis.
            // A manual conversation or disabled rollout must not spend provider work.
            $rollout = $this->settings->rolloutFor($company);
            if ($conversation->automation_mode === Conversation::AUTOMATION_MODE_MANUAL) {
                return $this->record($company, $conversation, $message, [
                    'rollout' => $rollout,
                    'decision' => CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW,
                    'reason_codes' => ['conversation_manual_mode'],
                    'action' => null,
                    'requires_human_review' => false,
                ]);
            }

            if ($conversation->automation_mode !== Conversation::AUTOMATION_MODE_AUTOMATIC) {
                return $this->record($company, $conversation, $message, [
                    'rollout' => $rollout,
                    'decision' => CopilotAutomationAuthorityPolicy::DECISION_DISABLED,
                    'reason_codes' => ['conversation_not_automatic'],
                    'action' => null,
                    'requires_human_review' => false,
                ]);
            }

            if (! $this->settings->globallyEnabled()) {
                return $this->record($company, $conversation, $message, [
                    'rollout' => $rollout,
                    'decision' => CopilotAutomationAuthorityPolicy::DECISION_DISABLED,
                    'reason_codes' => ['global_act_safe_disabled'],
                    'action' => null,
                    'requires_human_review' => false,
                ]);
            }

            if ($rollout === CopilotAutomationAuthorityPolicy::ROLLOUT_DISABLED) {
                return $this->record($company, $conversation, $message, [
                    'rollout' => $rollout,
                    'decision' => CopilotAutomationAuthorityPolicy::DECISION_DISABLED,
                    'reason_codes' => ['company_rollout_disabled'],
                    'action' => null,
                    'requires_human_review' => false,
                ]);
            }

            $expectedActiveOrderId = $conversation->active_order_id === null
                ? null
                : (int) $conversation->active_order_id;
            $analysis = $this->copilot->analyze($conversation->fresh());
            $decision = $this->policy->decide(
                $conversation,
                $analysis,
                $this->settings->rolloutFor($company),
                $this->settings->globallyEnabled(),
                (string) $message->content,
            );
            $event = $this->record($company, $conversation, $message, $decision, $analysis);

            if ($decision['requires_human_review']) {
                $this->requireHumanReview($company, $conversation, $message, $decision, $analysis);

                return $event;
            }

            $this->resolveCompletedClarificationReview($conversation, $analysis);

            if ($decision['decision'] !== CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY
                && $decision['decision'] !== CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION) {
                return $event;
            }

            // Re-check the two user-controlled gates immediately before any effect.
            $conversation->refresh();
            if (! $this->isCurrentInboundMessage($conversation, $message)
                || $conversation->automation_mode !== Conversation::AUTOMATION_MODE_AUTOMATIC
                || (int) $conversation->automation_version !== $expectedAutomationVersion
                || $this->activeOrderId($conversation) !== $expectedActiveOrderId
                || $this->settings->rolloutFor($company) !== CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE
                || ! $this->settings->globallyEnabled()) {
                return $this->markSkipped($event, 'execution_gate_changed');
            }

            try {
                if ($decision['decision'] === CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION) {
                    $order = $this->stageNewOrder($company, $conversation, $analysis);
                    $event->forceFill(['order_id' => $order->id])->save();
                    $conversation->refresh();
                }

                $delivery = $this->whatsapp->sendTextMessage(
                    $company,
                    (string) $conversation->whatsapp_identifier,
                    $this->groundedReply($analysis),
                    [
                        'conversation' => $conversation,
                        'sender_type' => 'ai',
                        'reply_to_message_id' => $message->id,
                        'reply_to_provider_message_id' => $message->external_message_id,
                        'client_reference' => $this->clientReference($message),
                    ],
                );

                $event->forceFill([
                    'status' => $delivery->status === 'failed' ? AutomationEvent::STATUS_FAILED : AutomationEvent::STATUS_DISPATCHED,
                    'response_payload' => [
                        'execution_result' => $delivery->status === 'failed' ? 'outbound_failed' : 'completed',
                        'outbound_delivery_id' => $delivery->id,
                        'outbound_message_id' => $delivery->message_id,
                        'outbound_status' => $delivery->status,
                        'order_staged' => $event->order_id !== null,
                    ],
                    'error_message' => $delivery->status === 'failed' ? 'Não foi possível enviar a resposta automática.' : null,
                    'dispatched_at' => $delivery->status === 'failed' ? null : now(),
                    'processed_at' => now(),
                ])->save();
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => AutomationEvent::STATUS_FAILED,
                    'error_message' => 'Não foi possível concluir a automação segura.',
                    'response_payload' => ['error_code' => 'copilot_act_safe_execution_failed'],
                    'processed_at' => now(),
                ])->save();

                $this->requireHumanReview($company, $conversation, $message, [
                    'reason_codes' => ['copilot_act_safe_execution_failed'],
                    'requires_human_review' => true,
                ]);
            }

            return $event->refresh();
        });
    }

    /** @param array<string,mixed> $analysis */
    private function stageNewOrder(Company $company, Conversation $conversation, array $analysis): Order
    {
        $order = $this->orders->createDraft($company, [
            'payer_customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'origin_channel' => Order::CHANNEL_WHATSAPP,
            'entry_mode' => Order::CHANNEL_WHATSAPP,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'is_manual' => false,
            'human_review_required' => true,
            'customer_confirmation_required' => true,
            'status_notes' => 'Rascunho operacional criado pela automação segura.',
        ]);

        foreach ((array) data_get($analysis, 'draft_order.items', []) as $item) {
            if (! is_array($item) || ($item['valid'] ?? false) !== true) {
                throw new \DomainException('copilot_item_not_validated');
            }

            $product = $this->eligibility->apply(Product::query())
                ->where('company_id', $company->id)
                ->whereKey((int) ($item['menu_item_id'] ?? 0))
                ->where('is_active', true)
                ->where('is_available_by_default', true)
                ->first();
            if (! $product instanceof Product) {
                throw new \DomainException('copilot_product_unavailable');
            }

            $this->orders->addItem($order, $product, [
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price_cents' => (int) ($item['unit_price_cents'] ?? $product->base_price_cents),
                'options' => (array) ($item['validated_order_options'] ?? []),
                'selected_components' => (array) ($item['resolved_selections'] ?? []),
                'removed_ingredients' => (array) ($item['removed_components'] ?? []),
                'item_notes' => (string) ($item['item_notes'] ?? ''),
            ]);
        }

        $conversation->forceFill(['active_order_id' => $order->id])->save();

        return $order->refresh();
    }

    /** @param array<string,mixed> $analysis */
    private function groundedReply(array $analysis): string
    {
        $reply = trim((string) ($analysis['suggested_reply'] ?? ''));

        if ($reply === '') {
            throw new \DomainException('copilot_safe_reply_missing');
        }

        return $reply;
    }

    private function isCurrentInboundMessage(Conversation $conversation, Message $message): bool
    {
        $latestInboundId = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->latest('id')
            ->value('id');

        return (int) $latestInboundId === (int) $message->id;
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $analysis */
    private function record(Company $company, Conversation $conversation, Message $message, array $decision, array $analysis = []): AutomationEvent
    {
        return AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => CopilotAutomationSettings::PROVIDER,
            'event_type' => AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            'status' => in_array($decision['decision'] ?? null, [CopilotAutomationAuthorityPolicy::DECISION_DISABLED, CopilotAutomationAuthorityPolicy::DECISION_SHADOW], true)
                ? AutomationEvent::STATUS_SKIPPED
                : AutomationEvent::STATUS_RECORDED,
            'requires_human_confirmation' => (bool) ($decision['requires_human_review'] ?? false),
            'payload' => [
                'automation_version' => (int) $conversation->automation_version,
                'conversation_mode' => (string) $conversation->automation_mode,
                'rollout' => (string) ($decision['rollout'] ?? 'disabled'),
                'decision' => (string) ($decision['decision'] ?? 'disabled'),
                'action' => $decision['action'] ?? null,
                'reason_codes' => array_values((array) ($decision['reason_codes'] ?? [])),
                'intent' => (string) ($analysis['intent'] ?? 'UNKNOWN'),
                'operational_status' => data_get($analysis, 'metadata.operational_status'),
                'safe_result_status' => (bool) ($analysis['requires_human_review'] ?? true) ? 'requires_human_review' : 'safe',
                'target_state' => data_get($analysis, 'proposal.target.state'),
                'guarded_reply_present' => trim((string) ($analysis['suggested_reply'] ?? '')) !== '',
                'clarification_required' => is_array($analysis['clarification'] ?? null),
                'clarification_type' => data_get($analysis, 'clarification.type'),
                'clarification_option_count' => count((array) data_get($analysis, 'clarification.options', [])),
                'clarification_context' => $this->clarificationContext($conversation, $message, $decision, $analysis),
                'clarification_source_event_id' => data_get($analysis, 'metadata.clarification_continuity.source_event_id'),
                'clarification_resolution' => data_get($analysis, 'metadata.clarification_continuity.resolution'),
                'clarification_matched_option_id' => data_get($analysis, 'metadata.clarification_continuity.matched_option_id'),
                'waiting_for_customer' => ($decision['action'] ?? null) === 'send_safe_clarification'
                    || array_intersect(
                        ['resolved_order_clarification', 'fulfillment_required'],
                        (array) ($decision['reason_codes'] ?? []),
                    ) !== [],
                'guard_results' => [
                    // Analysis is intentionally conservative. The policy decision above,
                    // not this telemetry field, controls whether a human action is required.
                    'requires_human_review' => (bool) ($analysis['requires_human_review'] ?? true),
                    'policy_requires_human_review' => (bool) ($decision['requires_human_review'] ?? false),
                    'missing_information_count' => count((array) ($analysis['missing_information'] ?? [])),
                    'warnings_count' => count((array) ($analysis['warnings'] ?? [])),
                    'missing_information_codes' => $this->codes((array) ($analysis['missing_information'] ?? [])),
                    'warning_codes' => $this->codes((array) ($analysis['warnings'] ?? [])),
                ],
            ],
            'response_payload' => [
                'execution_result' => 'not_executed',
            ],
            'processed_at' => now(),
        ]);
    }

    /** @param list<array<string,mixed>> $entries @return list<string> */
    private function codes(array $entries): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $entry): string => trim((string) ($entry['code'] ?? '')),
            $entries,
        ))));
    }

    /** @param array<string, mixed> $decision @param array<string, mixed> $analysis @return array<string, mixed>|null */
    private function clarificationContext(Conversation $conversation, Message $message, array $decision, array $analysis): ?array
    {
        $clarification = $analysis['clarification'] ?? null;
        if (($decision['action'] ?? null) !== 'send_safe_clarification'
            || ! is_array($clarification)
            || ($clarification['type'] ?? null) !== 'MEAT'
            || ! in_array(($clarification['source'] ?? null), ['DAILY_MENU', 'PRODUCT_CONFIGURATION'], true)
            || ($clarification['grounded'] ?? false) !== true) {
            return null;
        }

        $item = data_get($analysis, 'draft_order.items.0');
        $optionIds = array_values(array_unique(array_filter(array_map(
            fn (mixed $option): int => (int) data_get($option, 'component_id'),
            (array) ($clarification['options'] ?? []),
        ))));
        if (! is_array($item) || count((array) data_get($analysis, 'draft_order.items', [])) !== 1 || count($optionIds) < 2 || count($optionIds) > 5) {
            return null;
        }

        return [
            'type' => 'ambiguous_meat',
            'source' => (string) ($clarification['source'] ?? 'DAILY_MENU'),
            'selection_group' => 'meat',
            'source_message_id' => (int) $message->id,
            'active_order_id' => $conversation->active_order_id === null ? null : (int) $conversation->active_order_id,
            'candidate' => [
                'product_id' => (int) ($item['menu_item_id'] ?? 0),
                'product_slug' => (string) ($item['menu_item_slug'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ],
            'option_component_ids' => $optionIds,
        ];
    }

    private function markSkipped(AutomationEvent $event, string $reasonCode): AutomationEvent
    {
        $payload = (array) $event->payload;
        $payload['decision'] = CopilotAutomationAuthorityPolicy::DECISION_DISABLED;
        $payload['reason_codes'] = array_values(array_unique([...(array) ($payload['reason_codes'] ?? []), $reasonCode]));

        $event->forceFill([
            'status' => AutomationEvent::STATUS_SKIPPED,
            'payload' => $payload,
            'processed_at' => now(),
        ])->save();

        return $event->refresh();
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $analysis */
    private function requireHumanReview(Company $company, Conversation $conversation, Message $message, array $decision, array $analysis = []): void
    {
        $conversation->forceFill(['human_review_required' => true])->save();
        $reason = $this->humanReviewReason($analysis);
        $customer = trim((string) $conversation->customer()->value('name'));
        $this->alerts->open(
            company: $company,
            type: ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: $customer === '' ? 'Revisão necessária' : "Revisão necessária — {$customer}",
            message: $reason['message'],
            conversation: $conversation,
            messageModel: $message,
            deduplicationKey: 'copilot-act-safe-review:'.$conversation->id.':'.$reason['key'],
            metadata: [
                'reason_codes' => array_values((array) ($decision['reason_codes'] ?? [])),
                'missing_information_codes' => $this->codes((array) ($analysis['missing_information'] ?? [])),
                'warning_codes' => $this->codes((array) ($analysis['warnings'] ?? [])),
            ],
        );
    }

    /** @param array<string,mixed> $analysis @return array{key:string,message:string} */
    private function humanReviewReason(array $analysis): array
    {
        $missing = $this->codes((array) ($analysis['missing_information'] ?? []));
        $warnings = $this->codes((array) ($analysis['warnings'] ?? []));
        $product = trim((string) data_get($analysis, 'proposal.items.0.product_name', 'Pedido'));

        if (in_array('CARNE', $missing, true)) {
            return ['key' => 'missing-meat', 'message' => "{$product} precisa de confirmação: falta escolher a carne."];
        }
        if (in_array('ADDRESS', $missing, true)) {
            return ['key' => 'missing-address', 'message' => 'Pedido precisa de confirmação: falta informar o endereço de entrega.'];
        }
        if (in_array('LOCATION_UNAVAILABLE', $warnings, true)) {
            return ['key' => 'location-unavailable', 'message' => 'Atendimento precisa de apoio: confirme o endereço do restaurante.'];
        }

        $key = strtolower($missing[0] ?? $warnings[0] ?? 'general');

        return ['key' => preg_replace('/[^a-z0-9_-]+/', '-', $key) ?: 'general', 'message' => 'A conversa precisa de conferência da equipe antes de continuar.'];
    }

    /** @param array<string,mixed> $analysis */
    private function resolveCompletedClarificationReview(Conversation $conversation, array $analysis): void
    {
        if (data_get($analysis, 'metadata.clarification_continuity.resolution') !== 'resolved') {
            return;
        }

        ConversationAlert::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)
            ->where('deduplication_key', 'copilot-act-safe-review:'.$conversation->id.':missing-meat')
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->each(fn (ConversationAlert $alert) => $this->alerts->resolve($alert));

        $hasActiveOperationalAlert = ConversationAlert::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->currentActionable()
            ->exists();

        if (! $hasActiveOperationalAlert && $conversation->human_review_required) {
            $conversation->forceFill(['human_review_required' => false])->save();
        }
    }

    private function clientReference(Message $message): string
    {
        return 'copilot-act-safe:v1:inbound:'.$message->id;
    }

    private function activeOrderId(Conversation $conversation): ?int
    {
        return $conversation->active_order_id === null ? null : (int) $conversation->active_order_id;
    }
}
