<?php

namespace App\Services\Conversations;

use App\Exceptions\WhatsAppMessageSendFailedException;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\User;
use App\Models\WhatsAppMediaFile;
use App\Models\WhatsAppMessageDelivery;
use App\Services\Ai\AiAutomationService;
use App\Services\Payments\PaymentWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ConversationWorkflowService
{
    public function __construct(
        private readonly AiAutomationService $automation,
        private readonly ConversationAlertService $alerts,
        private readonly PaymentWorkflowService $payments,
        private readonly PrintWorkflowService $printing,
        private readonly WhatsAppService $whatsapp,
    ) {}

    public function switchMode(Conversation $conversation, string $mode, ?User $user = null, ?string $reason = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $mode, $user, $reason): Conversation {
            $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $previousVersion = (int) ($conversation->automation_version ?? 0);

            $conversation = $this->automation->switchMode($conversation, $mode, $user, $reason);
            $conversation->forceFill([
                'assigned_user_id' => $mode === Conversation::AUTOMATION_MODE_MANUAL ? $user?->id : null,
                'handoff_reason' => $mode === Conversation::AUTOMATION_MODE_MANUAL ? $reason : null,
                'ai_paused_at' => $mode === Conversation::AUTOMATION_MODE_MANUAL ? now() : null,
                'automation_version' => $previousVersion + 1,
            ])->save();

            if ($mode === Conversation::AUTOMATION_MODE_MANUAL) {
                $this->alerts->open(
                    company: $conversation->company()->firstOrFail(),
                    type: ConversationAlert::TYPE_HUMAN_REQUESTED,
                    severity: ConversationAlert::SEVERITY_WARNING,
                    title: 'Atendimento manual assumido',
                    message: 'A automacao foi pausada para esta conversa.',
                    conversation: $conversation,
                    deduplicationKey: 'manual-mode:'.$conversation->id,
                    metadata: ['actor_id' => $user?->id],
                );
            }

            return $conversation->refresh();
        });
    }

    public function sendHumanMessage(
        Company $company,
        Conversation $conversation,
        User $user,
        string $body,
        ?string $clientReference = null,
        ?int $replyToMessageId = null,
    ): Conversation {
        $body = trim($body);

        if ($body === '') {
            throw new DomainException('Digite uma mensagem antes de enviar.');
        }

        if ((int) $conversation->company_id !== (int) $company->id) {
            throw new DomainException('Conversa nao pertence ao restaurante atual.');
        }

        $failedDelivery = null;

        $conversation = DB::transaction(function () use ($company, $conversation, $user, $body, $clientReference, $replyToMessageId, &$failedDelivery): Conversation {
            $conversation = Conversation::query()
                ->where('company_id', $company->id)
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->automation_mode !== Conversation::AUTOMATION_MODE_MANUAL) {
                $this->switchMode(
                    conversation: $conversation,
                    mode: Conversation::AUTOMATION_MODE_MANUAL,
                    user: $user,
                    reason: 'Atendente respondeu pela interface.',
                );
                $conversation = $conversation->refresh();
            }

            $recipient = $conversation->whatsapp_identifier ?: $conversation->customer()->value('whatsapp_id') ?: $conversation->customer()->value('phone');

            if (! is_string($recipient) || trim($recipient) === '') {
                throw new DomainException('A conversa nao possui telefone WhatsApp valido para envio.');
            }

            $replyToMessage = null;
            if ($replyToMessageId !== null) {
                $replyToMessage = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereKey($replyToMessageId)
                    ->first();

                if (! $replyToMessage instanceof Message) {
                    throw new DomainException('A mensagem citada não pertence a esta conversa.');
                }

                if (! is_string($replyToMessage->external_message_id) || $replyToMessage->external_message_id === '') {
                    throw new DomainException('A mensagem citada não possui referência disponível no WhatsApp.');
                }
            }

            $delivery = $this->whatsapp->sendTextMessage($company, $recipient, $body, [
                'conversation' => $conversation,
                'sender_type' => 'human',
                'sent_by_user_id' => $user->id,
                'client_reference' => $clientReference,
                'reply_to_message_id' => $replyToMessage?->id,
                'reply_to_provider_message_id' => $replyToMessage?->external_message_id,
            ]);

            if ($delivery->status === 'failed') {
                $failedDelivery = $delivery;

                $this->alerts->open(
                    company: $company,
                    type: ConversationAlert::TYPE_MESSAGE_SEND_FAILED,
                    severity: ConversationAlert::SEVERITY_CRITICAL,
                    title: 'Falha ao enviar mensagem',
                    message: $delivery->error_message ?: 'A mensagem não foi entregue pelo provedor WhatsApp.',
                    conversation: $conversation,
                    deduplicationKey: 'message-send-failed:'.$delivery->message_id,
                    metadata: [
                        'delivery_id' => $delivery->id,
                        'error_code' => data_get($delivery->safe_payload, 'error_code'),
                    ],
                );
            }

            $conversation->forceFill([
                'last_business_message_at' => now(),
                'last_message_at' => now(),
                'unread_count' => 0,
            ])->save();

            return $conversation->refresh();
        });

        if ($failedDelivery instanceof WhatsAppMessageDelivery) {
            throw new WhatsAppMessageSendFailedException(
                $failedDelivery->error_message ?: 'Não foi possível enviar a mensagem pelo WhatsApp.',
                (int) $conversation->id,
                $failedDelivery->message_id ? (int) $failedDelivery->message_id : null,
                (string) (data_get($failedDelivery->safe_payload, 'error_code') ?: 'whatsapp_provider_rejected'),
            );
        }

        return $conversation;
    }

    public function retryHumanMessage(Company $company, Conversation $conversation, Message $message, User $user): Conversation
    {
        if ((int) $conversation->company_id !== (int) $company->id || (int) $message->conversation_id !== (int) $conversation->id) {
            throw new DomainException('Mensagem não pertence a esta conversa.');
        }

        $delivery = $this->whatsapp->retryTextMessage($company, $message);

        if ($delivery->status === WhatsAppMessageDelivery::STATUS_FAILED) {
            $this->alerts->open(
                company: $company,
                type: ConversationAlert::TYPE_MESSAGE_SEND_FAILED,
                severity: ConversationAlert::SEVERITY_CRITICAL,
                title: 'Falha ao reenviar mensagem',
                message: $delivery->error_message ?: 'A mensagem não foi entregue pelo provedor WhatsApp.',
                conversation: $conversation,
                deduplicationKey: 'message-send-failed:'.$delivery->message_id,
                metadata: [
                    'delivery_id' => $delivery->id,
                    'actor_id' => $user->id,
                    'error_code' => data_get($delivery->safe_payload, 'error_code'),
                ],
            );

            throw new WhatsAppMessageSendFailedException(
                $delivery->error_message ?: 'Não foi possível reenviar a mensagem pelo WhatsApp.',
                (int) $conversation->id,
                $delivery->message_id ? (int) $delivery->message_id : null,
                (string) (data_get($delivery->safe_payload, 'error_code') ?: 'whatsapp_provider_rejected'),
            );
        }

        ConversationAlert::query()
            ->where('company_id', $company->id)
            ->where('deduplication_key', 'message-send-failed:'.$message->id)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->each(fn (ConversationAlert $alert) => $this->alerts->resolve($alert, $user));

        return $conversation->refresh();
    }

    public function approvePaymentProof(
        Company $company,
        Conversation $conversation,
        PaymentProof $proof,
        User $user,
        int $confirmedAmountCents,
        ?string $notes = null,
    ): Conversation {
        $this->assertProofBelongsToConversation($company, $conversation, $proof);

        $payment = $proof->payment()->firstOrFail();

        if ($proof->status === PaymentProof::STATUS_ACCEPTED && $payment->status === Payment::STATUS_CONFIRMED) {
            return $conversation->refresh();
        }

        $this->payments->confirmPayment($payment, $user, [
            'confirmed_amount_cents' => $confirmedAmountCents,
            'notes' => $notes,
        ]);

        $order = $proof->order()->firstOrFail()->refresh();
        if ($order->items()->exists() && $order->latest_print_job_id === null) {
            $this->printing->generateTicket($order, $user);
        }

        $proof->forceFill([
            'status' => PaymentProof::STATUS_ACCEPTED,
            'review_notes' => $notes,
        ])->save();

        $this->alerts->open(
            company: $company,
            type: 'payment_approved',
            severity: ConversationAlert::SEVERITY_INFO,
            title: 'Pagamento aprovado',
            message: 'Comprovante aprovado por atendente.',
            conversation: $conversation,
            order: $order,
            payment: $payment,
            paymentProof: $proof,
            deduplicationKey: 'payment-approved:'.$proof->id,
        );

        ConversationAlert::query()
            ->where('company_id', $company->id)
            ->where('payment_proof_id', $proof->id)
            ->where('type', ConversationAlert::TYPE_PAYMENT_PROOF_RECEIVED)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->each(fn (ConversationAlert $alert) => $this->alerts->resolve($alert, $user));

        $recipient = $conversation->whatsapp_identifier ?: $conversation->customer()->value('whatsapp_id') ?: $conversation->customer()->value('phone');
        if (is_string($recipient) && trim($recipient) !== '') {
            $this->whatsapp->sendTextMessage($company, $recipient, 'Pagamento confirmado. Obrigado! Vamos seguir com o seu pedido.', [
                'conversation' => $conversation,
                'sender_type' => 'human',
                'sent_by_user_id' => $user->id,
            ]);
        }

        return $conversation->refresh();
    }

    public function rejectPaymentProof(
        Company $company,
        Conversation $conversation,
        PaymentProof $proof,
        User $user,
        string $reason,
    ): Conversation {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Informe o motivo da rejeicao do comprovante.');
        }

        $this->assertProofBelongsToConversation($company, $conversation, $proof);
        $payment = $proof->payment()->firstOrFail();

        if ($proof->status === PaymentProof::STATUS_REJECTED && $payment->status === Payment::STATUS_REJECTED) {
            return $conversation->refresh();
        }

        $proof = $this->payments->rejectProof($proof, $user, $reason);

        ConversationAlert::query()
            ->where('company_id', $company->id)
            ->where('payment_proof_id', $proof->id)
            ->where('type', ConversationAlert::TYPE_PAYMENT_PROOF_RECEIVED)
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->each(fn (ConversationAlert $alert) => $this->alerts->resolve($alert, $user));

        $this->alerts->open(
            company: $company,
            type: ConversationAlert::TYPE_PAYMENT_EVIDENCE_REJECTED,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: 'Evidência de pagamento rejeitada',
            message: $reason,
            conversation: $conversation,
            order: $proof->order()->first(),
            payment: $payment,
            paymentProof: $proof,
            deduplicationKey: 'payment-rejected:'.$proof->id,
        );

        $recipient = $conversation->whatsapp_identifier ?: $conversation->customer()->value('whatsapp_id') ?: $conversation->customer()->value('phone');
        if (is_string($recipient) && trim($recipient) !== '') {
            $replyToMessage = $this->paymentProofSourceMessage($company, $conversation, $proof);

            $this->whatsapp->sendTextMessage($company, $recipient, 'Nao conseguimos validar essa evidência. Pode enviar um novo comprovante ou chamar a atendente?', [
                'conversation' => $conversation,
                'sender_type' => 'system',
                'message_source' => 'deterministic_payment_proof_rejection',
                'action_type' => 'deterministic_payment_proof_rejection',
                'client_reference' => 'payment-proof-rejection:'.$proof->id,
                'reply_to_message_id' => $replyToMessage?->id,
                'reply_to_provider_message_id' => $replyToMessage?->external_message_id,
            ]);
        }

        return $conversation->refresh();
    }

    private function paymentProofSourceMessage(Company $company, Conversation $conversation, PaymentProof $proof): ?Message
    {
        $metadata = (array) $proof->metadata;
        $messageId = (int) ($metadata['message_id'] ?? 0);

        if ($messageId > 0) {
            $message = $this->replyablePaymentProofMessage($conversation, $messageId);
            if ($message instanceof Message) {
                return $message;
            }
        }

        $mediaId = (int) ($metadata['whatsapp_media_file_id'] ?? 0);
        if ($mediaId <= 0) {
            return null;
        }

        $media = WhatsAppMediaFile::query()
            ->whereKey($mediaId)
            ->where('company_id', $company->id)
            ->first();

        return $media instanceof WhatsAppMediaFile
            ? $this->replyablePaymentProofMessage($conversation, (int) $media->message_id)
            : null;
    }

    private function replyablePaymentProofMessage(Conversation $conversation, int $messageId): ?Message
    {
        if ($messageId <= 0) {
            return null;
        }

        return Message::query()
            ->whereKey($messageId)
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('external_message_id')
            ->where('external_message_id', '!=', '')
            ->first();
    }

    private function assertProofBelongsToConversation(Company $company, Conversation $conversation, PaymentProof $proof): void
    {
        $order = $proof->order()->firstOrFail();
        $belongsToConversation = (int) $order->conversation_id === (int) $conversation->id
            || (int) ($conversation->active_order_id ?? 0) === (int) $order->id;

        if ((int) $order->company_id !== (int) $company->id
            || (int) $conversation->company_id !== (int) $company->id
            || ! $belongsToConversation) {
            throw new DomainException('Comprovante nao pertence a esta conversa.');
        }
    }
}
