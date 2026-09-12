<?php

namespace App\Services\Ai;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Delivery\DeliveryRoutingService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes the small, explicitly allowed Level 2 surface after the read-only
 * Copilot has produced a validated result. Delivery quotes and pending Pix
 * charges still pass through their canonical workflows; confirmation,
 * cancellation, and configuration remain outside this service's authority.
 */
final class CopilotAutomationService
{
    public function __construct(
        private readonly ConversationCopilotService $copilot,
        private readonly CopilotAutomationAuthorityPolicy $policy,
        private readonly CopilotAutomationSettings $settings,
        private readonly CopilotProductEligibility $eligibility,
        private readonly OrderWorkflowService $orders,
        private readonly PaymentWorkflowService $payments,
        private readonly DeliveryRoutingService $deliveryRouting,
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
                if ($existing->status === AutomationEvent::STATUS_FAILED
                    && in_array(data_get($existing->payload, 'decision'), [
                        CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY,
                        CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION,
                    ], true)
                    && count((array) data_get($existing->payload, 'reply_messages', [])) > 1) {
                    if (! $this->isCurrentInboundMessage($conversation, $message)) {
                        return $this->markSkipped($existing, 'stale_retry_discarded');
                    }
                    $this->dispatchReply($existing, $company, $conversation, $message, [
                        'suggested_reply' => (string) data_get($existing->payload, 'reply_messages.0', ''),
                        'reply_messages' => (array) data_get($existing->payload, 'reply_messages', []),
                    ]);
                }

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
            $analysis = $this->copilot->analyze($conversation->fresh(), $message);
            $decisionRollout = $this->settings->rolloutFor($company);
            $globalEnabled = $this->settings->globallyEnabled();
            $decision = $this->policy->decide(
                $conversation,
                $analysis,
                $decisionRollout,
                $globalEnabled,
                (string) $message->content,
            );
            if (in_array($decision['decision'], [
                CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY,
                CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION,
            ], true)) {
                $this->resolveSupersededLowConfidenceReviews($conversation, $message, $analysis, $decision);
            } elseif (in_array('pending_human_review_blocks_mutation', $decision['reason_codes'], true)) {
                $safeCandidate = $this->policy->decideForSandbox(
                    $analysis,
                    $decisionRollout,
                    $globalEnabled,
                    (string) $message->content,
                );
                if ($safeCandidate['decision'] === CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION
                    && $this->resolveSupersededLowConfidenceReviews($conversation, $message, $analysis, $safeCandidate) > 0) {
                    $conversation->refresh();
                    $decision = $this->policy->decide(
                        $conversation,
                        $analysis,
                        $decisionRollout,
                        $globalEnabled,
                        (string) $message->content,
                    );
                }
            }
            $event = $this->record($company, $conversation, $message, $decision, $analysis);

            if ($decision['requires_human_review']) {
                $conversation->refresh();
                if (! $this->isCurrentInboundMessage($conversation, $message)) {
                    return $this->markSkipped($event, 'stale_review_discarded');
                }
                $this->requireHumanReview($company, $conversation, $message, $decision, $analysis);

                if (in_array(($decision['action'] ?? null), ['send_handoff_reply', 'send_payment_confirmation_notice'], true)) {
                    try {
                        $this->dispatchReply($event, $company, $conversation, $message, $analysis);
                    } catch (Throwable $exception) {
                        $event->forceFill([
                            'status' => AutomationEvent::STATUS_FAILED,
                            'error_message' => 'Não foi possível enviar a mensagem de transferência.',
                            'response_payload' => ['error_code' => 'copilot_handoff_reply_failed'],
                            'processed_at' => now(),
                        ])->save();
                    }
                }

                return $event->refresh();
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
                    if (($decision['action'] ?? null) === 'prepare_order_payment') {
                        $order = $this->activeOrderForPayment($company, $conversation, $analysis);
                        $payment = $this->preparePixPayment($order);
                    } else {
                        $order = $this->stageNewOrder($company, $conversation, $analysis);
                        $payment = data_get($analysis, 'draft_order.payment_method') === Payment::METHOD_PIX
                            ? $this->preparePixPayment($order)
                            : null;
                        $analysis = $this->stagedOrderReply($company, $order->fresh(), $analysis);
                    }
                    $event->forceFill(['order_id' => $order->id])->save();
                    $this->storeExecutionContext($event, $analysis, $order, $payment ?? null);
                    $conversation->refresh();
                }

                $this->dispatchReply($event, $company, $conversation, $message, $analysis);
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
        $fulfillment = (string) data_get($analysis, 'draft_order.fulfillment', Order::FULFILLMENT_PICKUP);
        $order = $this->orders->createDraft($company, [
            'payer_customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'origin_channel' => Order::CHANNEL_WHATSAPP,
            'entry_mode' => Order::CHANNEL_WHATSAPP,
            'fulfillment_type' => $fulfillment,
            'is_manual' => false,
            'human_review_required' => false,
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

        if ($fulfillment === Order::FULFILLMENT_DELIVERY) {
            $location = data_get($analysis, 'metadata.customer_location')
                ?? data_get($analysis, 'metadata.pending_customer_location');
            if (is_array($location)
                && is_numeric($location['latitude'] ?? null)
                && is_numeric($location['longitude'] ?? null)) {
                try {
                    $this->deliveryRouting->setCoordinates($order, (float) $location['latitude'], (float) $location['longitude']);
                } catch (\DomainException) {
                    if (empty($order->fresh()->delivery_address_snapshot)) {
                        throw new \DomainException('copilot_delivery_location_not_persisted');
                    }
                }
            } else {
                $address = trim((string) data_get($analysis, 'draft_order.address', ''));
                if ($address === '' || $address === 'Localização compartilhada via WhatsApp') {
                    throw new \DomainException('copilot_delivery_address_missing');
                }
                $this->deliveryRouting->geocodeAndSetAddress($order, $address);
            }
        }

        $conversation->forceFill(['active_order_id' => $order->id])->save();

        return $order->refresh();
    }

    /** @param array<string,mixed> $analysis */
    private function activeOrderForPayment(Company $company, Conversation $conversation, array $analysis): Order
    {
        $orderId = (int) data_get($analysis, 'metadata.active_order_id');
        $order = Order::query()
            ->where('company_id', $company->id)
            ->where('conversation_id', $conversation->id)
            ->whereKey($orderId)
            ->first();

        if (! $order instanceof Order || (int) $conversation->active_order_id !== (int) $order->id) {
            throw new \DomainException('copilot_active_order_not_available');
        }

        return $order;
    }

    private function preparePixPayment(Order $order): Payment
    {
        $existing = $order->payments()
            ->where('method', Payment::METHOD_PIX)
            ->whereIn('status', [
                Payment::STATUS_PENDING,
                Payment::STATUS_AWAITING_PROOF,
                Payment::STATUS_PROOF_RECEIVED,
            ])
            ->latest('id')
            ->first();
        if ($existing instanceof Payment) {
            return $existing;
        }

        return $this->payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'status' => Payment::STATUS_AWAITING_PROOF,
            'amount_cents' => (int) $order->total_cents,
            'customer_id' => $order->payer_customer_id,
            'metadata' => ['source' => 'copilot_whatsapp'],
        ]);
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function stagedOrderReply(Company $company, Order $order, array $analysis): array
    {
        $order->loadMissing(['items', 'deliveryAddress']);
        $items = $order->items->map(function ($item): string {
            $components = collect(is_array($item->selected_components) ? $item->selected_components : [])
                ->filter(fn (mixed $component): bool => is_string($component) && trim($component) !== '')
                ->map(fn (string $component): string => '• '.$component)
                ->implode("\n");

            return '*'.$item->quantity.'x '.$item->product_name.'*'.($components === '' ? '' : "\n".$components);
        })->implode("\n\n");
        $address = trim((string) data_get($order->delivery_address_snapshot, 'formatted_address', data_get($order->delivery_address_snapshot, 'address', '')));
        $summary = "Perfeito 😊 Seu pedido ficou assim:\n\n{$items}";
        if ($address !== '') {
            $summary .= "\n\n📍 *Entrega*\n{$address}";
        }
        $summary .= "\n\nSubtotal: *R$ {$this->money((int) $order->subtotal_cents)}*";
        if ($order->fulfillment_type === Order::FULFILLMENT_DELIVERY) {
            $summary .= "\nTaxa de entrega: *R$ {$this->money((int) $order->delivery_fee_cents)}*";
        }
        $summary .= "\nTotal: *R$ {$this->money((int) $order->total_cents)}*";

        if (data_get($analysis, 'draft_order.payment_method') !== Payment::METHOD_PIX) {
            $messages = [$summary, 'Como você prefere pagar?'];

            return [...$analysis, 'suggested_reply' => implode("\n\n", $messages), 'reply_messages' => $messages];
        }

        $company->loadMissing('setting');
        $key = trim((string) (data_get($company->setting?->settings, 'payments.pix.public_key')
            ?? data_get($company->setting?->settings, 'payments.pix.key', '')));
        $holder = trim((string) data_get($company->setting?->settings, 'payments.pix.holder_name', ''));
        $pix = $key === '' ? 'A chave Pix ainda não está configurada.' : "Chave Pix: *{$key}*";
        if ($key !== '' && $holder !== '') {
            $pix .= "\nFavorecido: {$holder}";
        }
        $messages = [$summary, $pix."\n\nDepois do pagamento, envie o comprovante aqui para nossa equipe conferir."];

        return [...$analysis, 'suggested_reply' => implode("\n\n", $messages), 'reply_messages' => $messages];
    }

    /** @param array<string,mixed> $analysis */
    private function storeExecutionContext(AutomationEvent $event, array $analysis, Order $order, ?Payment $payment): void
    {
        $payload = (array) $event->payload;
        $replyMessages = $this->groundedReplies($analysis);
        $payload['reply_messages'] = $replyMessages;
        $objective = $payment instanceof Payment ? 'WAIT_PAYMENT_PROOF' : 'ASK_PAYMENT_METHOD';
        $goal = [
            'type' => $payment instanceof Payment ? 'provide_value' : 'choose_option',
            'slot' => $payment instanceof Payment ? 'payment_proof' : 'payment_method',
            'product_id' => null,
            'allowed_values' => [],
        ];
        $orderContext = is_array($payload['order_context'] ?? null) ? $payload['order_context'] : [];
        $orderContext = [
            ...$orderContext,
            'has_context' => true,
            'lifecycle' => 'materialized',
            'phase' => $objective,
            'next_objective' => $objective,
            'pending_slot' => ['code' => $payment instanceof Payment ? 'PAYMENT_PROOF' : 'PAYMENT_METHOD', 'slot' => $goal['slot']],
            'missing_slots' => [$payment instanceof Payment ? 'PAYMENT_PROOF' : 'PAYMENT_METHOD'],
            'missing_fields' => [$payment instanceof Payment ? 'PAYMENT_PROOF' : 'PAYMENT_METHOD'],
            'assistant_goal' => $goal,
            'fulfillment' => (string) $order->fulfillment_type,
            'address' => data_get($order->delivery_address_snapshot, 'formatted_address', data_get($order->delivery_address_snapshot, 'address')),
            'payment' => [
                'method' => data_get($analysis, 'draft_order.payment_method') ?: null,
                'status' => $payment?->status,
            ],
            'materialized_order_id' => (int) $order->id,
        ];
        $payload['order_context'] = $orderContext;
        $payload['assistant_goal'] = $goal;
        $payload['phase'] = $objective;
        $payload['next_objective'] = $objective;
        $payload['turn_trace']['next_action'] = strtolower($objective);
        $payload['turn_trace']['reply'] = $replyMessages;
        $payload['resolved_turn']['phase'] = $objective;
        $payload['resolved_turn']['next_objective'] = $objective;
        $payload['resolved_turn']['next_question'] = $goal;
        $payload['turn_envelope']['canonical_state'] = $orderContext['draft_order'] ?? null;
        $payload['turn_envelope']['phase'] = $objective;
        $payload['turn_envelope']['next_objective'] = $objective;
        $payload['turn_envelope']['final_reply_messages'] = $replyMessages;
        if ($payment instanceof Payment) {
            $payload['payment_id'] = (int) $payment->id;
            $payload['payment_status'] = (string) $payment->status;
        }
        $event->forceFill(['payload' => $payload])->save();
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
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

    /** @param array<string,mixed> $analysis @return list<string> */
    private function groundedReplies(array $analysis): array
    {
        $messages = collect(is_array($analysis['reply_messages'] ?? null) ? $analysis['reply_messages'] : [])
            ->filter(fn (mixed $reply): bool => is_string($reply) && trim($reply) !== '')
            ->map(fn (string $reply): string => trim($reply))
            ->take(2)
            ->values()
            ->all();

        return $messages === [] ? [$this->groundedReply($analysis)] : $messages;
    }

    /** @param array<string,mixed> $analysis */
    private function dispatchReply(AutomationEvent $event, Company $company, Conversation $conversation, Message $message, array $analysis): void
    {
        $replies = $this->groundedReplies($analysis);
        $attempts = collect((array) data_get($event->response_payload, 'outbound_deliveries', []));

        foreach ($replies as $index => $reply) {
            // A multipart reply or retry can outlive the snapshot that produced
            // it. Revalidate the causal inbound immediately before every send.
            if (! $this->isCurrentInboundMessage($conversation, $message)) {
                $this->markSkipped($event, 'stale_outbound_discarded');

                return;
            }
            $successful = $attempts->contains(fn (mixed $attempt): bool => is_array($attempt)
                && (int) ($attempt['part'] ?? -1) === $index
                && ($attempt['status'] ?? null) !== 'failed');
            if ($successful) {
                continue;
            }

            $attemptNumber = $attempts->filter(fn (mixed $attempt): bool => is_array($attempt)
                && (int) ($attempt['part'] ?? -1) === $index)->count();
            $failedMessageId = (int) data_get($attempts->last(fn (mixed $attempt): bool => is_array($attempt)
                && (int) ($attempt['part'] ?? -1) === $index), 'message_id', 0);
            $failedMessage = $attemptNumber > 0 ? Message::query()->find($failedMessageId) : null;
            $delivery = $failedMessage instanceof Message && $failedMessage->delivery_status === 'failed'
                ? $this->whatsapp->retryTextMessage($company, $failedMessage)
                : $this->whatsapp->sendTextMessage(
                    $company,
                    (string) $conversation->whatsapp_identifier,
                    $reply,
                    [
                        'conversation' => $conversation,
                        'sender_type' => 'ai',
                        'reply_to_message_id' => $message->id,
                        'reply_to_provider_message_id' => $message->external_message_id,
                        'client_reference' => $this->clientReference($message, $index, count($replies), $attemptNumber),
                    ],
                );
            $attempts->push([
                'part' => $index,
                'attempt' => $attemptNumber + 1,
                'delivery_id' => (int) $delivery->id,
                'message_id' => (int) $delivery->message_id,
                'status' => (string) $delivery->status,
            ]);
            $failed = $delivery->status === 'failed';
            $event->forceFill([
                'status' => $failed ? AutomationEvent::STATUS_FAILED : AutomationEvent::STATUS_RECORDED,
                'response_payload' => [
                    'execution_result' => $failed ? 'outbound_failed' : 'partially_completed',
                    'outbound_delivery_id' => $delivery->id,
                    'outbound_message_id' => $delivery->message_id,
                    'outbound_status' => $delivery->status,
                    'outbound_deliveries' => $attempts->values()->all(),
                    'order_staged' => $event->order_id !== null,
                ],
                'error_message' => $failed ? 'Não foi possível enviar a resposta automática.' : null,
                'processed_at' => now(),
            ])->save();

            if ($failed) {
                return;
            }
        }

        $last = $attempts->last();
        $event->forceFill([
            'status' => AutomationEvent::STATUS_DISPATCHED,
            'response_payload' => [
                ...(array) $event->response_payload,
                'execution_result' => 'completed',
                'outbound_delivery_id' => $last['delivery_id'] ?? null,
                'outbound_message_id' => $last['message_id'] ?? null,
                'outbound_status' => $last['status'] ?? null,
                'outbound_deliveries' => $attempts->values()->all(),
                'order_staged' => $event->order_id !== null,
            ],
            'error_message' => null,
            'dispatched_at' => now(),
            'processed_at' => now(),
        ])->save();
    }

    private function isCurrentInboundMessage(Conversation $conversation, Message $message): bool
    {
        $latestInboundId = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderByRaw('COALESCE(received_at, created_at) DESC')
            ->orderByDesc('id')
            ->value('id');

        return (int) $latestInboundId === (int) $message->id;
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $analysis */
    private function record(Company $company, Conversation $conversation, Message $message, array $decision, array $analysis = []): AutomationEvent
    {
        $turnTrace = data_get($analysis, 'metadata.turn_trace');
        if (! is_array($turnTrace)) {
            $stale = in_array('stale_inbound_message', (array) ($decision['reason_codes'] ?? []), true);
            $turnTrace = [
                'turn_id' => 'conversation:'.$conversation->id.':inbound:'.$message->id,
                'trigger_message_ids' => [filled($message->external_message_id) ? (string) $message->external_message_id : (string) $message->id],
                'trigger_message_record_ids' => [(int) $message->id],
                'state_before' => null,
                'pending_slot_before' => null,
                'semantic_result' => null,
                'canonical_resolution' => null,
                'state_delta' => [],
                'constraints' => [],
                'state_after' => null,
                'next_action' => $stale ? 'discard_stale_turn' : null,
                'provider_interpretation_called' => false,
                'provider_naturalizer_called' => false,
                'reply' => [],
                'review_reason' => null,
                'stale_discarded' => $stale,
            ];
        }
        if (($decision['requires_human_review'] ?? false) === true) {
            $turnTrace['review_reason'] = array_values((array) ($decision['reason_codes'] ?? []));
        }
        $replyMessages = trim((string) ($analysis['suggested_reply'] ?? '')) === ''
            && empty($analysis['reply_messages'])
                ? []
                : $this->groundedReplies($analysis);
        $turnEnvelope = is_array(data_get($analysis, 'metadata.turn_envelope'))
            ? (array) data_get($analysis, 'metadata.turn_envelope')
            : [
                'conversation_id' => (int) $conversation->id,
                'trigger_inbound_message_id' => (int) $message->id,
                'trigger_external_message_id' => filled($message->external_message_id) ? (string) $message->external_message_id : null,
                'trigger_occurred_at' => ($message->received_at ?? $message->created_at)?->toIso8601String(),
                'previous_order_context' => null,
                'semantic_result' => null,
                'validated_delta' => [],
                'canonical_state' => null,
                'phase' => null,
                'next_objective' => null,
                'information_response' => null,
                'reply_composer' => null,
                'final_reply_messages' => [],
            ];
        $turnEnvelope['final_reply_messages'] = $replyMessages;
        $turnEnvelope['authority_decision'] = [
            'decision' => (string) ($decision['decision'] ?? 'disabled'),
            'action' => $decision['action'] ?? null,
            'reason_codes' => array_values((array) ($decision['reason_codes'] ?? [])),
            'requires_human_review' => (bool) ($decision['requires_human_review'] ?? false),
        ];

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
                'reply_messages' => $replyMessages,
                'clarification_required' => is_array($analysis['clarification'] ?? null),
                'clarification_type' => data_get($analysis, 'clarification.type'),
                'clarification_option_count' => count((array) data_get($analysis, 'clarification.options', [])),
                'clarification_context' => $this->clarificationContext($conversation, $message, $decision, $analysis),
                'order_context' => $this->orderContext($analysis, $decision),
                'assistant_goal' => data_get($analysis, 'metadata.order_context.assistant_goal', data_get($analysis, 'metadata.assistant_goal')),
                'conversation_references' => array_values((array) data_get($analysis, 'metadata.conversation_references', [])),
                'phase' => data_get($analysis, 'metadata.order_context.phase'),
                'next_objective' => data_get($analysis, 'metadata.order_context.next_objective'),
                'turn_id' => data_get($analysis, 'metadata.turn_trace.turn_id', 'conversation:'.$conversation->id.':inbound:'.$message->id),
                'trigger_message_ids' => data_get($analysis, 'metadata.turn_trace.trigger_message_ids', [filled($message->external_message_id) ? (string) $message->external_message_id : (string) $message->id]),
                'turn_trace' => $turnTrace,
                'turn_envelope' => $turnEnvelope,
                'resolved_turn' => data_get($analysis, 'metadata.resolved_turn'),
                'clarification_source_event_id' => data_get($analysis, 'metadata.clarification_continuity.source_event_id'),
                'clarification_resolution' => data_get($analysis, 'metadata.clarification_continuity.resolution'),
                'clarification_matched_option_id' => data_get($analysis, 'metadata.clarification_continuity.matched_option_id'),
                'waiting_for_customer' => filled(data_get($analysis, 'metadata.order_context.next_objective'))
                    || ($decision['action'] ?? null) === 'send_safe_clarification'
                    || in_array(($decision['action'] ?? null), ['send_order_clarification', 'send_grounded_reply'], true)
                        && array_intersect(['normal_missing_information', 'recoverable_unknown_clarification'], (array) ($decision['reason_codes'] ?? [])) !== []
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

    /** @param list<array<string,mixed>|string> $entries @return list<string> */
    private function codes(array $entries): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $entry): string => trim((string) (is_array($entry) ? ($entry['code'] ?? '') : $entry)),
            $entries,
        ))));
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function orderContext(array $analysis, array $decision = []): array
    {
        $reduced = data_get($analysis, 'metadata.order_context');
        if (is_array($reduced)) {
            $required = (bool) ($decision['requires_human_review'] ?? false);
            $reduced['review'] = [
                'required' => $required,
                'reason' => $required ? array_values((array) ($decision['reason_codes'] ?? [])) : null,
            ];

            return $reduced;
        }

        $draft = is_array($analysis['draft_order'] ?? null) ? $analysis['draft_order'] : [];
        $offeredProductIds = array_values(array_filter(array_map(
            'intval',
            (array) data_get($analysis, 'metadata.offered_product_ids', []),
        )));
        $components = collect((array) data_get($analysis, 'metadata.recognized_components', []))
            ->filter(fn (mixed $component): bool => is_array($component) && (int) ($component['id'] ?? 0) > 0)
            ->map(fn (array $component): array => [
                'id' => (int) $component['id'],
                'name' => (string) ($component['name'] ?? ''),
                'type' => (string) ($component['type'] ?? ''),
            ])
            ->values()
            ->all();
        $hasContext = (array) data_get($draft, 'items', []) !== [] || $components !== [] || $offeredProductIds !== [];

        return [
            'has_context' => $hasContext,
            'draft_order' => $hasContext ? $draft : null,
            'selected_components' => $components,
            'missing_fields' => $hasContext ? $this->codes((array) ($analysis['missing_information'] ?? [])) : [],
            'offered_product_ids' => $hasContext ? $offeredProductIds : [],
            'customer_location' => $hasContext
                ? (data_get($analysis, 'metadata.customer_location') ?? data_get($analysis, 'metadata.pending_customer_location'))
                : null,
        ];
    }

    /** @param array<string, mixed> $decision @param array<string, mixed> $analysis @return array<string, mixed>|null */
    private function clarificationContext(Conversation $conversation, Message $message, array $decision, array $analysis): ?array
    {
        if (($decision['action'] ?? null) === 'send_order_clarification'
            && data_get($analysis, 'metadata.reply_source') === 'order_clarification') {
            $recognized = collect(data_get($analysis, 'metadata.recognized_components', []))
                ->filter(fn (mixed $component): bool => is_array($component) && (int) ($component['id'] ?? 0) > 0)
                ->map(fn (array $component): array => [
                    'id' => (int) $component['id'],
                    'name' => (string) ($component['name'] ?? ''),
                    'type' => (string) ($component['type'] ?? ''),
                ])
                ->unique('id')
                ->values()
                ->all();

            return [
                'type' => 'product_selection',
                'source' => 'OPERATIONAL_CATALOG',
                'source_message_id' => (int) $message->id,
                'active_order_id' => $conversation->active_order_id === null ? null : (int) $conversation->active_order_id,
                'recognized_components' => $recognized,
                'unavailable_components' => array_values((array) data_get($analysis, 'metadata.unavailable_components', [])),
                'candidate_product' => data_get($analysis, 'metadata.candidate_product'),
                'incompatible_component_ids' => array_values((array) data_get($analysis, 'metadata.incompatible_component_ids', [])),
            ];
        }

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
        if (is_array($payload['turn_trace'] ?? null)) {
            $payload['turn_trace']['stale_discarded'] = true;
            $payload['turn_trace']['next_action'] = 'discard_stale_turn';
        }

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
        $reason = $this->humanReviewReason($analysis, $decision);
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

    /** @param array<string,mixed> $analysis @param array<string,mixed> $decision @return array{key:string,message:string} */
    private function humanReviewReason(array $analysis, array $decision = []): array
    {
        $missing = $this->codes((array) ($analysis['missing_information'] ?? []));
        $warnings = $this->codes((array) ($analysis['warnings'] ?? []));
        $product = trim((string) data_get($analysis, 'proposal.items.0.product_name', 'Pedido'));
        $handoff = (string) data_get($analysis, 'metadata.handoff_reason', '');

        if ($handoff !== '') {
            return match ($handoff) {
                'financial_exception' => ['key' => 'financial-exception', 'message' => 'Cliente solicita uma condição financeira ou comercial fora da automação.'],
                'out_of_domain' => ['key' => 'out-of-domain', 'message' => 'Cliente solicita ajuda fora do escopo do atendimento do restaurante.'],
                'customer_requested_human' => ['key' => 'customer-requested-human', 'message' => 'Cliente pediu atendimento de uma pessoa da equipe.'],
                default => ['key' => 'irreparable-message', 'message' => 'A mensagem continuou incompreensível após uma tentativa de clarificação.'],
            };
        }

        if (in_array('LOCATION_UNAVAILABLE', $warnings, true)) {
            return ['key' => 'location-unavailable', 'message' => 'Atendimento precisa de apoio: confirme o endereÃ§o do restaurante.'];
        }
        if ($warnings !== []) {
            $warning = $warnings[0];

            return [
                'key' => 'warning-'.strtolower($warning),
                'message' => 'A equipe precisa conferir a causa operacional '.$warning.' antes de continuar.',
            ];
        }
        $reasonCode = (string) data_get($decision, 'reason_codes.0', '');
        if ($reasonCode !== '') {
            return [
                'key' => preg_replace('/[^a-z0-9_-]+/', '-', strtolower($reasonCode)) ?: 'general',
                'message' => 'A equipe precisa conferir a causa '.$reasonCode.' antes de continuar.',
            ];
        }

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

    /** @param array<string,mixed> $analysis */
    private function resolveSupersededLowConfidenceReviews(Conversation $conversation, Message $message, array $analysis, array $decision = []): int
    {
        $protectedKeyFragments = [
            'financial-exception',
            'customer-requested-human',
            'out-of-domain',
            'irreparable-message',
        ];
        $protectedReasonFragments = [
            'handoff_',
            'financial_or_administrative',
            'copilot_act_safe_execution_failed',
            'conversation_manual_mode',
        ];

        $currentMissing = collect([
            ...$this->codes((array) ($analysis['missing_information'] ?? [])),
            ...array_map('strval', (array) data_get($analysis, 'metadata.order_context.required_missing_slots', [])),
        ])->map(fn (string $code): string => strtoupper(trim($code)))->filter()->unique();
        $currentWarnings = collect($this->codes((array) ($analysis['warnings'] ?? [])))
            ->map(fn (string $code): string => strtoupper(trim($code)))
            ->filter()
            ->unique();
        $currentReasons = collect($this->codes((array) ($decision['reason_codes'] ?? [])))
            ->map(fn (string $code): string => strtoupper(trim($code)))
            ->filter()
            ->unique();
        $alerts = ConversationAlert::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->where('type', ConversationAlert::TYPE_LOW_CONFIDENCE_AI)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->where(function ($query) use ($message): void {
                $query->whereNull('message_id')->orWhere('message_id', '<', $message->id);
            })
            ->get()
            ->reject(function (ConversationAlert $alert) use ($protectedKeyFragments, $protectedReasonFragments): bool {
                $key = strtolower((string) $alert->deduplication_key);
                $reasons = strtolower(implode(' ', (array) data_get($alert->metadata, 'reason_codes', [])));

                return collect($protectedKeyFragments)->contains(fn (string $fragment): bool => str_contains($key, $fragment))
                    || collect($protectedReasonFragments)->contains(fn (string $fragment): bool => str_contains($reasons, $fragment));
            })
            ->filter(function (ConversationAlert $alert) use ($currentMissing, $currentWarnings, $currentReasons): bool {
                $alertMissing = collect((array) data_get($alert->metadata, 'missing_information_codes', []))
                    ->map(fn (mixed $code): string => strtoupper(trim((string) $code)))
                    ->filter()
                    ->unique();
                $alertWarnings = collect((array) data_get($alert->metadata, 'warning_codes', []))
                    ->map(fn (mixed $code): string => strtoupper(trim((string) $code)))
                    ->filter()
                    ->unique();
                $alertReasons = collect((array) data_get($alert->metadata, 'reason_codes', []))
                    ->map(fn (mixed $code): string => strtoupper(trim((string) $code)))
                    ->filter()
                    ->unique();
                if ($alertMissing->isEmpty() && $alertWarnings->isEmpty()) {
                    return false;
                }

                $sameMissingCause = $alertMissing->isEmpty() || $alertMissing->intersect($currentMissing)->isNotEmpty();
                $sameWarningCause = $alertWarnings->isEmpty() || $alertWarnings->intersect($currentWarnings)->isNotEmpty();
                $sameDecisionCause = $alertReasons->isEmpty() || $alertReasons->intersect($currentReasons)->isNotEmpty();

                return ! ($sameMissingCause && $sameWarningCause && $sameDecisionCause);
            });
        $alerts->each(fn (ConversationAlert $alert) => $this->alerts->resolve($alert));

        return $alerts->count();
    }

    private function clientReference(Message $message, int $part = 0, int $partCount = 1, int $attempt = 0): string
    {
        $reference = 'copilot-act-safe:v1:inbound:'.$message->id;
        if ($partCount > 1) {
            $reference .= ':part:'.($part + 1);
        }
        if ($attempt > 0) {
            $reference .= ':retry:'.$attempt;
        }

        return $reference;
    }

    private function activeOrderId(Conversation $conversation): ?int
    {
        return $conversation->active_order_id === null ? null : (int) $conversation->active_order_id;
    }
}
