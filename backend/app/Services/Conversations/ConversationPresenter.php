<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\WhatsAppMediaFile;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\CustomerActiveOrderResolver;
use Illuminate\Support\Collection;

class ConversationPresenter
{
    public function __construct(
        private readonly OperationalCrmPresenter $operational,
        private readonly ConversationOperationalStatusResolver $operationalStatus,
        private readonly CustomerActiveOrderResolver $activeOrders,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function conversation(Conversation $conversation): array
    {
        $conversation->loadMissing([
            'customer.addresses',
            'assignedUser',
            'pinnedBy',
            'manualTakeoverBy',
            'activeOrder.payerCustomer',
            'activeOrder.items.options',
            'activeOrder.statusHistories',
            'activeOrder.latestPrintJob',
            'activeOrder.payments.proofs',
            'messages.mediaFiles',
            'messages.whatsappMessageDeliveries',
            'messages.replyTo',
            'messages.pinnedBy',
            'whatsappMessageDeliveries',
            'alerts.payment',
            'alerts.paymentProof',
        ]);

        $messages = $conversation->messages
            ->filter(fn (Message $message): bool => $message->hidden_at === null)
            ->sortBy('created_at')
            ->map(fn (Message $message): array => $this->message($message))
            ->values();

        $alerts = $conversation->alerts
            ->reject(fn (ConversationAlert $alert): bool => $alert->type === ConversationAlert::TYPE_UNREAD_MESSAGE)
            ->sortByDesc('created_at')
            ->map(fn (ConversationAlert $alert): array => $this->alert($alert))
            ->values();

        $activeOrder = $this->activeOrders->forConversation($conversation);
        $operationalStatus = $this->operationalStatus->resolve($conversation, $activeOrder);

        return [
            'id' => (string) $conversation->id,
            'customer' => $this->customer($conversation),
            'mode' => $this->modeFor($conversation),
            'automationMode' => $conversation->automation_mode,
            'automationStatus' => $conversation->automation_status,
            'automationVersion' => (int) ($conversation->automation_version ?? 0),
            'unread' => (int) ($conversation->unread_count ?? 0),
            'statusLabel' => $operationalStatus['label'],
            'operationalStatus' => $operationalStatus,
            'lastMessage' => (string) ($messages->last()['body'] ?? 'Sem mensagens recentes.'),
            'messages' => $messages,
            'linkedOrderId' => $activeOrder?->id ? (string) $activeOrder->id : null,
            'activeOrder' => $activeOrder instanceof Order ? $this->operational->order($activeOrder) : null,
            'assignedUser' => $conversation->assignedUser ? [
                'id' => (string) $conversation->assignedUser->id,
                'name' => $conversation->assignedUser->name,
            ] : null,
            'manualTakeoverBy' => $conversation->manualTakeoverBy ? [
                'id' => (string) $conversation->manualTakeoverBy->id,
                'name' => $conversation->manualTakeoverBy->name,
            ] : null,
            'handoffReason' => $conversation->handoff_reason ?? $conversation->manual_takeover_reason,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'isPinned' => $conversation->pinned_at !== null,
            'pinnedAt' => $conversation->pinned_at?->toIso8601String(),
            'alerts' => $alerts,
            'paymentReview' => $this->paymentReview($activeOrder),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function alert(ConversationAlert $alert): array
    {
        return [
            'id' => (string) $alert->id,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'message' => $alert->message,
            'status' => $alert->status,
            'conversationId' => $alert->conversation_id ? (string) $alert->conversation_id : null,
            'orderId' => $alert->order_id ? (string) $alert->order_id : null,
            'paymentId' => $alert->payment_id ? (string) $alert->payment_id : null,
            'paymentProofId' => $alert->payment_proof_id ? (string) $alert->payment_proof_id : null,
            'createdAt' => $alert->created_at?->toIso8601String(),
            'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
            'resolvedAt' => $alert->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(Message $message): array
    {
        $delivery = $message->relationLoaded('whatsappMessageDeliveries')
            ? $message->whatsappMessageDeliveries->sortByDesc('id')->first()
            : null;

        return [
            'id' => (string) $message->id,
            'sender' => $this->senderFor($message),
            'direction' => $message->direction ?: ($message->sender === 'customer' ? 'inbound' : 'outbound'),
            'type' => $message->type,
            'body' => $message->hidden_at !== null ? null : $this->displayBody($message),
            'timeLabel' => $message->created_at?->format('H:i') ?? '',
            'createdAt' => $message->created_at?->toIso8601String(),
            'occurredAt' => ($message->sent_at ?? $message->received_at ?? $message->created_at)?->toIso8601String(),
            'sentAt' => $message->sent_at?->toIso8601String(),
            'receivedAt' => $message->received_at?->toIso8601String(),
            'deliveredAt' => $message->delivered_at?->toIso8601String(),
            'readAt' => $message->read_at?->toIso8601String(),
            'failedAt' => $message->failed_at?->toIso8601String(),
            'status' => $message->delivery_status ?: 'received',
            'errorMessage' => $message->delivery_status === 'failed' ? $delivery?->error_message : null,
            'errorCode' => $message->delivery_status === 'failed'
                ? ($message->error_code ?: data_get($delivery?->safe_payload, 'error_code'))
                : null,
            'media' => $message->mediaFiles
                ->map(fn (WhatsAppMediaFile $media): array => [
                    'id' => (string) $media->id,
                    'type' => $media->media_type,
                    'name' => in_array($media->media_type, ['image', 'video', 'sticker'], true)
                        ? ''
                        : ($media->original_filename ?: 'Arquivo recebido'),
                    'mimeType' => $media->mime_type,
                    'filename' => $media->original_filename,
                    'sizeBytes' => $media->size_bytes,
                    'contentHash' => $media->checksum ?: $media->sha256,
                    'isVoiceNote' => $message->type === 'audio' && (
                        (bool) data_get($media->metadata, 'voice_note', false)
                        || data_get($media->metadata, 'recording_source') === 'browser'
                        || $message->direction === 'inbound'
                    ),
                    'status' => $media->status,
                    'url' => route('api.app.conversations.media.show', ['media' => $media->id], false),
                ])
                ->values()
                ->all(),
            'reactions' => collect((array) data_get($message->metadata, 'whatsapp_reactions', []))
                ->filter(fn (mixed $reaction): bool => is_array($reaction) && is_string(data_get($reaction, 'emoji')))
                ->map(fn (array $reaction): array => [
                    'emoji' => (string) $reaction['emoji'],
                    'source' => data_get($reaction, 'source') === 'operator' ? 'operator' : 'customer',
                    'createdAt' => data_get($reaction, 'created_at'),
                ])
                ->values()
                ->all(),
            'replyTo' => $message->replyTo ? [
                'id' => (string) $message->replyTo->id,
                'sender' => $this->senderFor($message->replyTo),
                'type' => $message->replyTo->type,
                'body' => $this->displayBody($message->replyTo),
            ] : null,
            'isPinned' => $message->pinned_at !== null,
            'pinnedAt' => $message->pinned_at?->toIso8601String(),
            'isRevoked' => (bool) data_get($message->metadata, 'message_revoked', false),
        ];
    }

    private function displayBody(Message $message): ?string
    {
        if ((bool) data_get($message->metadata, 'message_revoked', false)) {
            return null;
        }
        if ($message->content === null || $message->content === '') {
            return null;
        }

        if ($message->mediaFiles->isNotEmpty() && in_array($message->content, [
            '[audio recebido]', '[video recebido]', '[sticker recebido]', '[imagem recebida]', '[documento recebido]',
        ], true)) {
            return null;
        }

        return $message->content;
    }

    private function senderFor(Message $message): string
    {
        return match ($message->sender_type ?: $message->sender) {
            'human', 'attendant', 'agent', 'user' => 'attendant',
            'ai', 'assistant' => 'ai',
            default => 'customer',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $customer?->loadMissing('addresses');
        $defaultAddress = $customer?->addresses->firstWhere('is_default', true) ?? $customer?->addresses->first();

        return [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'phoneLabel' => $customer->phone ?: $conversation->whatsapp_identifier ?: 'Sem telefone cadastrado',
            'phone' => $customer->phone,
            'email' => $customer->email,
            'whatsappId' => $customer->whatsapp_id,
            'whatsappProfileName' => $customer->whatsapp_profile_name,
            'sourceChannel' => $customer->source_channel,
            'lastWhatsappAt' => $customer->last_whatsapp_at?->toIso8601String(),
            'tags' => array_values(array_filter([
                'WhatsApp',
                $customer->source_channel === 'whatsapp' ? 'Criado pelo WhatsApp' : null,
            ])),
            'creditBalance' => round(((int) $customer->credit_balance_cents) / 100, 2),
            'notes' => $customer->notes ? [$customer->notes] : [],
            'preferences' => [],
            'address' => $defaultAddress ? [
                'street' => $defaultAddress->street,
                'number' => $defaultAddress->number,
                'complement' => $defaultAddress->complement,
                'neighborhood' => $defaultAddress->neighborhood,
                'city' => $defaultAddress->city,
                'reference' => $defaultAddress->reference,
            ] : null,
        ];
    }

    private function modeFor(Conversation $conversation): string
    {
        return $conversation->automation_mode === Conversation::AUTOMATION_MODE_MANUAL ? 'manual' : 'ia';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentReview(?Order $order): ?array
    {
        if (! $order instanceof Order) {
            return null;
        }

        $payments = $order->relationLoaded('payments') ? $order->payments : $order->payments()->with('proofs')->get();
        $payment = $payments
            ->sortByDesc('id')
            ->first(fn (Payment $candidate): bool => in_array($candidate->status, [
                Payment::STATUS_AWAITING_PROOF,
                Payment::STATUS_PROOF_RECEIVED,
                Payment::STATUS_PENDING,
            ], true));

        if (! $payment instanceof Payment) {
            return null;
        }

        /** @var Collection<int, PaymentProof> $proofs */
        $proofs = $payment->relationLoaded('proofs') ? $payment->proofs : $payment->proofs()->get();
        $proof = $proofs
            ->sortByDesc('id')
            ->first();
        $mediaId = $proof?->metadata['whatsapp_media_file_id'] ?? null;

        return [
            'paymentId' => (string) $payment->id,
            'proofId' => $proof?->id ? (string) $proof->id : '',
            'orderId' => (string) $order->id,
            'orderCode' => $order->code,
            'customerName' => $this->orderCustomerName($order),
            'status' => $payment->status,
            'expectedTotal' => round((int) $order->total_cents / 100, 2),
            'amountCents' => $proof?->amount_cents,
            'method' => $payment->method,
            'receivedAt' => $proof?->received_at?->toIso8601String(),
            'fileName' => $proof?->original_filename,
            'mimeType' => $proof?->mime_type,
            'mediaUrl' => $mediaId ? route('api.app.conversations.media.show', ['media' => $mediaId], false) : null,
        ];
    }

    private function orderCustomerName(Order $order): string
    {
        $snapshot = trim((string) $order->customer_name_snapshot);

        if ($snapshot !== '') {
            return $snapshot;
        }

        return $order->payerCustomer?->name ?: 'Cliente avulso';
    }
}
