<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessageDelivery;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class WhatsAppService
{
    public function __construct(
        private readonly WhatsAppProviderInterface $provider,
        private readonly WhatsAppPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function connectionStatus(?Company $company = null): array
    {
        $status = $this->provider->connectionStatus()->toArray();

        if ($company !== null) {
            $status['accounts'] = $company->whatsappAccounts()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn (WhatsAppAccount $account): array => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'name' => $account->name,
                    'status' => $account->status,
                    'is_default' => $account->is_default,
                    'phone_number_id_present' => $account->phone_number_id !== null,
                    'business_account_id_present' => $account->business_account_id !== null,
                    'last_webhook_at' => $account->last_webhook_at,
                    'webhook_verified_at' => $account->webhook_verified_at,
                ])
                ->all();
        }

        return $status;
    }

    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $verifiedChallenge = $this->provider->verifyWebhook($mode, $token, $challenge);

        if ($verifiedChallenge !== null) {
            $this->markDefaultAccountWebhookVerified();
        }

        return $verifiedChallenge;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function storeWebhookEvent(
        array $payload,
        array $headers = [],
        ?string $method = null,
        ?string $sourceIp = null,
    ): WhatsAppWebhookEvent {
        return DB::transaction(function () use ($payload, $headers, $method, $sourceIp): WhatsAppWebhookEvent {
            $account = $this->resolveAccountFromPayload($payload);
            $eventType = $this->eventTypeForPayload($payload);

            return WhatsAppWebhookEvent::query()->create([
                'company_id' => $account?->company_id,
                'whatsapp_account_id' => $account?->id,
                'provider' => $this->provider->name(),
                'event_type' => $eventType,
                'provider_event_id' => $this->providerEventId($payload),
                'status' => WhatsAppWebhookEvent::STATUS_RECEIVED,
                'request_method' => $method,
                'signature_present' => $this->signaturePresent($headers),
                'source_ip_hash' => $this->sourceIpHash($sourceIp),
                'raw_payload' => $payload,
                'sanitized_payload' => $this->sanitizer->sanitize($payload),
                'received_at' => now(),
            ]);
        });
    }

    public function processWebhookEvent(WhatsAppWebhookEvent $event): WhatsAppWebhookEvent
    {
        return DB::transaction(function () use ($event): WhatsAppWebhookEvent {
            $event = WhatsAppWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            try {
                $messages = $this->provider->parseWebhookPayload($event->raw_payload ?? []);

                if ($messages === []) {
                    $event->forceFill([
                        'status' => WhatsAppWebhookEvent::STATUS_IGNORED,
                        'processed_at' => now(),
                    ])->save();

                    return $event->refresh();
                }

                foreach ($messages as $incomingMessage) {
                    $this->persistIncomingMessage($incomingMessage, $event);
                }

                $event->forceFill([
                    'status' => WhatsAppWebhookEvent::STATUS_PROCESSED,
                    'processed_at' => now(),
                ])->save();
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => WhatsAppWebhookEvent::STATUS_FAILED,
                    'error_message' => $exception->getMessage(),
                    'processed_at' => now(),
                ])->save();
            }

            return $event->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function sendTextMessage(Company $company, string $to, string $body, array $attributes = []): WhatsAppMessageDelivery
    {
        return DB::transaction(function () use ($company, $to, $body, $attributes): WhatsAppMessageDelivery {
            $account = $this->defaultAccountFor($company);
            $conversation = $this->resolveConversationForOutbound($company, $to, $attributes);
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender' => 'agent',
                'content' => $body,
                'type' => 'text',
                'provider' => $this->provider->name(),
                'external_recipient_id' => $to,
                'delivery_status' => WhatsAppMessageDelivery::STATUS_QUEUED,
                'metadata' => [
                    'source' => 'whatsapp_service',
                    'provider' => $this->provider->name(),
                ],
            ]);

            $delivery = WhatsAppMessageDelivery::query()->create([
                'company_id' => $company->id,
                'whatsapp_account_id' => $account?->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'provider' => $this->provider->name(),
                'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND,
                'message_type' => 'text',
                'recipient' => $to,
                'status' => WhatsAppMessageDelivery::STATUS_QUEUED,
                'content_preview' => Str::limit($body, 120),
                'safe_payload' => [
                    'provider' => $this->provider->name(),
                    'body_length' => strlen($body),
                    'recipient_present' => $to !== '',
                ],
            ]);

            $result = $this->provider->sendTextMessage(new OutgoingWhatsAppMessage(
                to: $to,
                body: $body,
                phoneNumberId: $account?->phone_number_id,
                metadata: ['delivery_id' => $delivery->id],
            ));

            $delivery->forceFill([
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->successful()
                    ? WhatsAppMessageDelivery::STATUS_SENT
                    : WhatsAppMessageDelivery::STATUS_FAILED,
                'safe_payload' => $result->safePayload,
                'sent_at' => $result->successful() ? now() : null,
                'failed_at' => $result->successful() ? null : now(),
                'error_message' => $result->errorMessage,
            ])->save();

            $message->forceFill([
                'external_message_id' => $result->providerMessageId,
                'delivery_status' => $delivery->status,
                'sent_at' => $delivery->sent_at,
            ])->save();

            return $delivery->refresh();
        });
    }

    private function persistIncomingMessage(IncomingWhatsAppMessage $incomingMessage, WhatsAppWebhookEvent $event): void
    {
        $account = $this->resolveAccountForIncoming($incomingMessage, $event);

        if ($account === null) {
            return;
        }

        $customer = $this->resolveCustomerForIncoming($account->company()->firstOrFail(), $incomingMessage);
        $conversation = $this->resolveOpenConversation($account->company_id, $customer->id);
        $content = $incomingMessage->text ?: '['.$incomingMessage->messageType.' message]';

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'content' => $content,
            'type' => $incomingMessage->messageType,
            'provider' => $incomingMessage->provider,
            'external_message_id' => $incomingMessage->providerMessageId,
            'external_sender_id' => $incomingMessage->from,
            'external_recipient_id' => $incomingMessage->to,
            'delivery_status' => WhatsAppMessageDelivery::STATUS_RECEIVED,
            'metadata' => $incomingMessage->safeMetadata,
            'received_at' => $incomingMessage->sentAt ?? now(),
        ]);

        WhatsAppMessageDelivery::query()->create([
            'company_id' => $account->company_id,
            'whatsapp_account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => $incomingMessage->provider,
            'provider_message_id' => $incomingMessage->providerMessageId,
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'message_type' => $incomingMessage->messageType,
            'recipient' => $incomingMessage->to,
            'sender' => $incomingMessage->from,
            'status' => WhatsAppMessageDelivery::STATUS_RECEIVED,
            'content_preview' => Str::limit($content, 120),
            'safe_payload' => $incomingMessage->safeMetadata,
        ]);

        $account->forceFill([
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'last_webhook_at' => now(),
            'connected_at' => $account->connected_at ?? now(),
        ])->save();
    }

    private function defaultAccountFor(Company $company): ?WhatsAppAccount
    {
        return $company->whatsappAccounts()
            ->where('provider', $this->provider->name())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function resolveAccountForIncoming(IncomingWhatsAppMessage $message, WhatsAppWebhookEvent $event): ?WhatsAppAccount
    {
        if ($event->whatsappAccount !== null) {
            return $event->whatsappAccount;
        }

        if ($message->providerAccountId !== null) {
            return WhatsAppAccount::query()
                ->where('provider', $this->provider->name())
                ->where('phone_number_id', $message->providerAccountId)
                ->first();
        }

        return WhatsAppAccount::query()
            ->where('provider', $this->provider->name())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function resolveAccountFromPayload(array $payload): ?WhatsAppAccount
    {
        $phoneNumberId = $this->phoneNumberIdFromPayload($payload);

        if ($phoneNumberId !== null) {
            $account = WhatsAppAccount::query()
                ->where('provider', $this->provider->name())
                ->where('phone_number_id', $phoneNumberId)
                ->first();

            if ($account !== null) {
                return $account;
            }
        }

        return WhatsAppAccount::query()
            ->where('provider', $this->provider->name())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function phoneNumberIdFromPayload(array $payload): ?string
    {
        if (isset($payload['phone_number_id'])) {
            return (string) $payload['phone_number_id'];
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneNumberId = Arr::get($change, 'value.metadata.phone_number_id');

                if ($phoneNumberId !== null) {
                    return (string) $phoneNumberId;
                }
            }
        }

        return null;
    }

    private function providerEventId(array $payload): ?string
    {
        return isset($payload['entry'][0]['id']) ? (string) $payload['entry'][0]['id'] : null;
    }

    private function eventTypeForPayload(array $payload): string
    {
        if (isset($payload['messages'])) {
            return WhatsAppWebhookEvent::EVENT_MESSAGE;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if (! empty($value['messages'])) {
                    return WhatsAppWebhookEvent::EVENT_MESSAGE;
                }

                if (! empty($value['statuses'])) {
                    return WhatsAppWebhookEvent::EVENT_STATUS;
                }
            }
        }

        return WhatsAppWebhookEvent::EVENT_WEBHOOK;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function signaturePresent(array $headers): bool
    {
        return array_key_exists('x-hub-signature-256', array_change_key_case($headers, CASE_LOWER));
    }

    private function sourceIpHash(?string $sourceIp): ?string
    {
        if ($sourceIp === null || $sourceIp === '') {
            return null;
        }

        return hash('sha256', $sourceIp.'|'.config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveConversationForOutbound(Company $company, string $to, array $attributes): Conversation
    {
        if (($attributes['conversation'] ?? null) instanceof Conversation) {
            return $attributes['conversation'];
        }

        if (($attributes['conversation_id'] ?? null) !== null) {
            return Conversation::query()->findOrFail($attributes['conversation_id']);
        }

        $customer = $this->resolveCustomerForPhone($company, $to, $attributes['customer_name'] ?? null);

        return $this->resolveOpenConversation($company->id, $customer->id);
    }

    private function resolveCustomerForIncoming(Company $company, IncomingWhatsAppMessage $message): Customer
    {
        return $this->resolveCustomerForPhone($company, (string) $message->from, $message->senderName);
    }

    private function resolveCustomerForPhone(Company $company, string $phone, ?string $name = null): Customer
    {
        $normalizedPhone = $this->normalizePhone($phone);

        return Customer::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'phone' => $normalizedPhone,
            ],
            [
                'name' => $name ?: 'Cliente WhatsApp',
            ],
        );
    }

    private function resolveOpenConversation(int $companyId, int $customerId): Conversation
    {
        return Conversation::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'channel' => 'whatsapp',
                'status' => 'open',
            ],
            [
                'started_at' => now(),
            ],
        );
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        return $normalized !== '' && $normalized !== null ? $normalized : $phone;
    }

    private function markDefaultAccountWebhookVerified(): void
    {
        $account = WhatsAppAccount::query()
            ->where('provider', $this->provider->name())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($account === null) {
            return;
        }

        $account->forceFill([
            'webhook_verified_at' => now(),
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'connected_at' => $account->connected_at ?? now(),
        ])->save();
    }
}
