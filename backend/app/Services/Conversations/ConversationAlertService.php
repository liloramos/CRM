<?php

namespace App\Services\Conversations;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\User;
use App\Models\WhatsAppMessageDelivery;
use App\Services\WhatsApp\WhatsAppErrorClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationAlertService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function open(
        Company $company,
        string $type,
        string $severity,
        string $title,
        ?string $message = null,
        ?Conversation $conversation = null,
        ?Order $order = null,
        ?Message $messageModel = null,
        ?Payment $payment = null,
        ?PaymentProof $paymentProof = null,
        ?string $deduplicationKey = null,
        array $metadata = [],
    ): ConversationAlert {
        return DB::transaction(function () use (
            $company,
            $type,
            $severity,
            $title,
            $message,
            $conversation,
            $order,
            $messageModel,
            $payment,
            $paymentProof,
            $deduplicationKey,
            $metadata,
        ): ConversationAlert {
            $key = $deduplicationKey ?: implode(':', array_filter([
                $type,
                $conversation?->id,
                $order?->id,
                $messageModel?->id,
                $paymentProof?->id,
            ]));

            $existing = ConversationAlert::query()
                ->where('company_id', $company->id)
                ->where('deduplication_key', $key)
                ->lockForUpdate()
                ->first();
            $startsNewCycle = ! $existing instanceof ConversationAlert
                || $existing->status === ConversationAlert::STATUS_RESOLVED;
            $notificationKey = $startsNewCycle
                ? Str::uuid()->toString()
                : data_get($existing?->metadata, 'notification_key');

            $alert = ConversationAlert::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'deduplication_key' => $key,
                ],
                [
                    'conversation_id' => $conversation?->id,
                    'order_id' => $order?->id,
                    'message_id' => $messageModel?->id,
                    'payment_id' => $payment?->id,
                    'payment_proof_id' => $paymentProof?->id,
                    'type' => $type,
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'status' => $startsNewCycle
                        ? ConversationAlert::STATUS_OPEN
                        : ($existing?->status ?? ConversationAlert::STATUS_OPEN),
                    'resolved_at' => null,
                    'resolved_by_user_id' => null,
                    'acknowledged_at' => $startsNewCycle ? null : $existing?->acknowledged_at,
                    'acknowledged_by_user_id' => $startsNewCycle ? null : $existing?->acknowledged_by_user_id,
                    'metadata' => [
                        ...$metadata,
                        'notification_key' => $notificationKey ?: Str::uuid()->toString(),
                    ],
                ],
            )->refresh();

            if ($conversation instanceof Conversation && in_array($type, ConversationAlert::HUMAN_HANDOFF_TYPES, true)) {
                $this->synchronizeHumanReviewRequired($conversation);
            }

            return $alert;
        });
    }

    /** @param list<string> $types */
    public function resolveActiveForConversation(Conversation $conversation, array $types, ?User $user = null): int
    {
        $alerts = ConversationAlert::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->whereIn('type', $types)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get();

        $alerts->each(fn (ConversationAlert $alert) => $this->resolve($alert, $user));

        return $alerts->count();
    }

    public function openMessageSendFailure(
        Company $company,
        ?Conversation $conversation,
        WhatsAppMessageDelivery $delivery,
        ?User $user = null,
    ): ConversationAlert {
        $errorCode = (string) (data_get($delivery->safe_payload, 'error_code') ?: WhatsAppErrorClassifier::PROVIDER_REJECTED);
        $systemic = in_array($errorCode, [
            WhatsAppErrorClassifier::TOKEN_INVALID,
            WhatsAppErrorClassifier::TOKEN_EXPIRED,
            WhatsAppErrorClassifier::CONFIGURATION_MISSING,
        ], true);
        $scopeId = $delivery->whatsapp_account_id ?: 'default';

        return $this->open(
            company: $company,
            type: ConversationAlert::TYPE_MESSAGE_SEND_FAILED,
            severity: $systemic ? ConversationAlert::SEVERITY_CRITICAL : ConversationAlert::SEVERITY_WARNING,
            title: $systemic ? 'WhatsApp precisa de atenção' : 'Mensagem não enviada',
            message: $delivery->error_message ?: 'A mensagem não foi entregue pelo provedor WhatsApp.',
            conversation: $systemic ? null : $conversation,
            messageModel: $systemic ? null : $delivery->message()->first(),
            deduplicationKey: $systemic
                ? "whatsapp-systemic:{$scopeId}:{$errorCode}"
                : 'message-send-failed:'.($conversation?->id ?: 'unknown').":{$errorCode}",
            metadata: [
                'scope' => $systemic ? 'account' : 'conversation',
                'whatsapp_account_id' => $delivery->whatsapp_account_id,
                'latest_delivery_id' => $delivery->id,
                'latest_message_id' => $delivery->message_id,
                'actor_id' => $user?->id,
                'error_code' => $errorCode,
            ],
        );
    }

    public function resolveMessageSendFailures(
        Company $company,
        ?Conversation $conversation,
        ?int $whatsAppAccountId,
        ?User $user = null,
    ): int {
        $accountScope = $whatsAppAccountId === null ? 'default' : (string) $whatsAppAccountId;
        $alerts = ConversationAlert::query()
            ->where('company_id', $company->id)
            ->where('type', ConversationAlert::TYPE_MESSAGE_SEND_FAILED)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->filter(function (ConversationAlert $alert) use ($conversation, $accountScope): bool {
                $sameConversation = $conversation instanceof Conversation
                    && (int) $alert->conversation_id === (int) $conversation->id;
                $alertAccount = data_get($alert->metadata, 'whatsapp_account_id');
                $sameAccount = data_get($alert->metadata, 'scope') === 'account'
                    && ($alertAccount === null ? 'default' : (string) $alertAccount) === $accountScope;

                return $sameConversation || $sameAccount;
            });

        $alerts->each(fn (ConversationAlert $alert) => $this->resolve($alert, $user));

        return $alerts->count();
    }

    public function resolveReopenedCustomerWindow(Conversation $conversation): int
    {
        $alerts = ConversationAlert::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->where('type', ConversationAlert::TYPE_MESSAGE_SEND_FAILED)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->filter(fn (ConversationAlert $alert): bool => in_array(data_get($alert->metadata, 'error_code'), [
                WhatsAppErrorClassifier::CUSTOMER_WINDOW_CLOSED,
                WhatsAppErrorClassifier::TEMPLATE_REQUIRED,
            ], true));

        $alerts->each(fn (ConversationAlert $alert) => $this->resolve($alert));

        return $alerts->count();
    }

    public function acknowledge(ConversationAlert $alert, ?User $user = null): ConversationAlert
    {
        if ($alert->status === ConversationAlert::STATUS_RESOLVED) {
            return $alert->refresh();
        }

        $alert->forceFill([
            'status' => ConversationAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id ?? $user?->id,
        ])->save();

        return $alert->refresh();
    }

    public function resolve(ConversationAlert $alert, ?User $user = null): ConversationAlert
    {
        $alert->forceFill([
            'status' => ConversationAlert::STATUS_RESOLVED,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id ?? $user?->id,
            'resolved_at' => now(),
            'resolved_by_user_id' => $user?->id,
        ])->save();

        if (in_array($alert->type, ConversationAlert::HUMAN_HANDOFF_TYPES, true)) {
            $conversation = $alert->conversation()->first();
            if ($conversation instanceof Conversation) {
                $this->synchronizeHumanReviewRequired($conversation);
            }
        }

        return $alert->refresh();
    }

    public function synchronizeHumanReviewRequired(Conversation $conversation): bool
    {
        $requiresHumanReview = ConversationAlert::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->whereIn('type', ConversationAlert::HUMAN_HANDOFF_TYPES)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->exists();

        if ((bool) $conversation->human_review_required !== $requiresHumanReview) {
            $conversation->forceFill(['human_review_required' => $requiresHumanReview])->save();
        }

        return $requiresHumanReview;
    }
}
