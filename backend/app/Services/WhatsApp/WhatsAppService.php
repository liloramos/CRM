<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Data\WhatsApp\NormalizedWhatsAppAudio;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMediaFile;
use App\Models\WhatsAppMessageDelivery;
use App\Models\WhatsAppWebhookEvent;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Conversations\PaymentProofCandidateClassifier;
use App\Services\Payments\PaymentWorkflowService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class WhatsAppService
{
    public function __construct(
        private readonly WhatsAppProviderInterface $provider,
        private readonly WhatsAppPayloadSanitizer $sanitizer,
        private readonly WhatsAppMediaStorageService $mediaStorage,
        private readonly ConversationAlertService $alerts,
        private readonly PaymentProofCandidateClassifier $paymentProofClassifier,
        private readonly ConversationAiService $ai,
        private readonly PaymentWorkflowService $payments,
        private readonly WhatsAppErrorClassifier $errors,
        private readonly WhatsAppInboundTrace $inboundTrace,
        private readonly WhatsAppPhoneResolver $phoneResolver,
        private readonly WhatsAppAudioNormalizer $audioNormalizer,
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
     * @param  array<string, mixed>  $headers
     */
    public function signatureIsValid(string $rawPayload, array $headers): bool
    {
        $secret = (string) config('chatbotcrm.whatsapp.meta.app_secret', '');

        if ($secret === '') {
            return true;
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $signature = $headers['x-hub-signature-256'][0] ?? $headers['x_hub_signature_256'][0] ?? null;

        if (! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, $signature);
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
        ?string $correlationId = null,
    ): WhatsAppWebhookEvent {
        $traceId = $correlationId ?? $this->inboundTrace->newCorrelationId();
        $this->inboundTrace->log($traceId, 'event_persist_started');
        $this->inboundTrace->log($traceId, 'payload_structure_checked', $this->payloadStructureTrace($payload));

        foreach ($this->payloadStructureWarnings($payload) as $warning) {
            $this->inboundTrace->log($traceId, $warning['stage'], [
                'error_code' => $warning['error_code'],
            ]);
        }

        try {
            return DB::transaction(function () use ($payload, $headers, $method, $sourceIp, $correlationId, $traceId): WhatsAppWebhookEvent {
                $account = $this->resolveAccountFromPayload($payload);
                $eventType = $this->eventTypeForPayload($payload);
                $deduplicationKey = $this->deduplicationKey($payload);

                if ($deduplicationKey !== null) {
                    $existing = WhatsAppWebhookEvent::query()
                        ->where('provider', $this->provider->name())
                        ->where('deduplication_key', $deduplicationKey)
                        ->first();

                    if ($existing instanceof WhatsAppWebhookEvent) {
                        $this->inboundTrace->log($traceId, 'event_duplicate', [
                            'event_id' => $existing->id,
                            'status' => $existing->status,
                        ]);

                        return $existing;
                    }
                }

                $sanitizedPayload = $this->sanitizer->sanitize($payload);

                if ($correlationId !== null) {
                    $sanitizedPayload['inbound_trace'] = ['correlation_id' => $correlationId];
                }

                $event = WhatsAppWebhookEvent::query()->create([
                    'company_id' => $account?->company_id,
                    'whatsapp_account_id' => $account?->id,
                    'provider' => $this->provider->name(),
                    'event_type' => $eventType,
                    'provider_event_id' => $this->providerEventId($payload),
                    'deduplication_key' => $deduplicationKey,
                    'status' => WhatsAppWebhookEvent::STATUS_RECEIVED,
                    'request_method' => $method,
                    'signature_present' => $this->signaturePresent($headers),
                    'source_ip_hash' => $this->sourceIpHash($sourceIp),
                    'raw_payload' => $payload,
                    'sanitized_payload' => $sanitizedPayload,
                    'received_at' => now(),
                ]);

                $this->inboundTrace->record($event, 'event_persisted', [
                    ...$this->traceContextForPayload($payload),
                    'account_id' => $account?->id,
                    'company_id' => $account?->company_id,
                    'status' => WhatsAppWebhookEvent::STATUS_RECEIVED,
                ]);

                return $event->refresh();
            });
        } catch (Throwable $exception) {
            $this->inboundTrace->log($traceId, 'event_persist_failed', [
                'error_code' => 'whatsapp_webhook_persistence_failed',
                'exception_type' => class_basename($exception),
            ]);

            throw $exception;
        }
    }

    public function processWebhookEvent(WhatsAppWebhookEvent $event): WhatsAppWebhookEvent
    {
        return DB::transaction(function () use ($event): WhatsAppWebhookEvent {
            $event = WhatsAppWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            try {
                $messages = $this->provider->parseWebhookPayload($event->raw_payload ?? []);
                $statusCount = $this->processStatusesFromPayload($event->raw_payload ?? [], $event);
                $this->inboundTrace->record($event, 'payload_parsed', [
                    ...$this->traceContextForPayload($event->raw_payload ?? []),
                    'message_count' => count($messages),
                    'status_count' => $statusCount,
                ]);

                if ($messages === [] && $statusCount === 0) {
                    $event->forceFill([
                        'status' => WhatsAppWebhookEvent::STATUS_IGNORED,
                        'processed_at' => now(),
                    ])->save();

                    $this->inboundTrace->record($event, 'event_completed', [
                        'status' => WhatsAppWebhookEvent::STATUS_IGNORED,
                    ]);

                    return $event->refresh();
                }

                foreach ($messages as $incomingMessage) {
                    $this->persistIncomingMessage($incomingMessage, $event);
                }

                $event->forceFill([
                    'status' => WhatsAppWebhookEvent::STATUS_PROCESSED,
                    'processed_at' => now(),
                ])->save();

                $this->inboundTrace->record($event, 'event_completed', [
                    'status' => WhatsAppWebhookEvent::STATUS_PROCESSED,
                ]);
            } catch (Throwable $exception) {
                $errorCode = $this->webhookProcessingErrorCode($exception);
                $event->forceFill([
                    'status' => WhatsAppWebhookEvent::STATUS_FAILED,
                    'error_message' => 'Falha ao processar evento do WhatsApp.',
                    'sanitized_payload' => array_merge($event->sanitized_payload ?? [], [
                        'processing_error_type' => class_basename($exception),
                    ]),
                    'processed_at' => now(),
                ])->save();

                $this->inboundTrace->record($event, 'event_failed', [
                    'error_code' => $errorCode,
                    'status' => WhatsAppWebhookEvent::STATUS_FAILED,
                ]);
            }

            return $event->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function sendTextMessage(Company $company, string $to, string $body, array $attributes = []): WhatsAppMessageDelivery
    {
        $recipient = $this->normalizeWhatsAppRecipient($to);

        return DB::transaction(function () use ($company, $recipient, $body, $attributes): WhatsAppMessageDelivery {
            $account = $this->defaultAccountFor($company);
            $conversation = $this->resolveConversationForOutbound($company, $recipient, $attributes);
            $recipientResolution = $this->resolveOutboundRecipient($conversation, $recipient);
            $recipient = $recipientResolution['value'];
            $clientReference = trim((string) ($attributes['client_reference'] ?? ''));

            if ($clientReference !== '') {
                $existingMessage = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)
                    ->where('metadata->client_reference', $clientReference)
                    ->first();

                if ($existingMessage instanceof Message) {
                    $existingDelivery = $existingMessage->whatsappMessageDeliveries()->latest('id')->first();

                    if ($existingDelivery instanceof WhatsAppMessageDelivery) {
                        return $existingDelivery;
                    }
                }
            }

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'reply_to_message_id' => $attributes['reply_to_message_id'] ?? null,
                'sender' => 'agent',
                'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND,
                'sender_type' => $attributes['sender_type'] ?? 'human',
                'content' => $body,
                'type' => 'text',
                'provider' => $this->provider->name(),
                'external_recipient_id' => $recipient,
                'delivery_status' => WhatsAppMessageDelivery::STATUS_QUEUED,
                'metadata' => [
                    'source' => 'whatsapp_service',
                    'provider' => $this->provider->name(),
                    'sender_type' => $attributes['sender_type'] ?? 'human',
                    'sent_by_user_id' => $attributes['sent_by_user_id'] ?? null,
                    'client_reference' => $clientReference !== '' ? $clientReference : null,
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
                'recipient' => $recipient,
                'status' => WhatsAppMessageDelivery::STATUS_QUEUED,
                'content_preview' => Str::limit($body, 120),
                'safe_payload' => [
                    'provider' => $this->provider->name(),
                    'body_length' => strlen($body),
                    'recipient_present' => $recipient !== '',
                    'recipient_source' => $recipientResolution['source'],
                    'recipient_digit_count' => $this->digitCount($recipient),
                    'recipient_suffix' => $this->identifierSuffix($recipient),
                ],
            ]);

            $result = $this->provider->sendTextMessage(new OutgoingWhatsAppMessage(
                to: $recipient,
                body: $body,
                phoneNumberId: $account?->phone_number_id,
                replyToProviderMessageId: $attributes['reply_to_provider_message_id'] ?? null,
                metadata: ['delivery_id' => $delivery->id],
            ));

            $delivery->forceFill([
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->successful()
                    ? WhatsAppMessageDelivery::STATUS_SENT
                    : WhatsAppMessageDelivery::STATUS_FAILED,
                'safe_payload' => array_merge((array) $delivery->safe_payload, $result->safePayload),
                'sent_at' => $result->successful() ? now() : null,
                'failed_at' => $result->successful() ? null : now(),
                'error_message' => $result->errorMessage,
            ])->save();

            $message->forceFill([
                'external_message_id' => $result->providerMessageId,
                'delivery_status' => $delivery->status,
                'sent_at' => $delivery->sent_at,
                'failed_at' => $delivery->failed_at,
                'error_code' => $result->errorCode,
            ])->save();

            $conversation->forceFill([
                'last_business_message_at' => now(),
                'last_message_at' => now(),
            ])->save();

            return $delivery->refresh();
        });
    }

    public function sendMediaMessage(Company $company, Conversation $conversation, UploadedFile $file, string $mediaType, string $caption = '', array $attributes = []): WhatsAppMessageDelivery
    {
        $allowed = [
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max' => 5 * 1024 * 1024],
            'document' => ['mimes' => ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'max' => 100 * 1024 * 1024],
            'video' => ['mimes' => ['video/mp4', 'video/3gpp'], 'max' => 16 * 1024 * 1024],
            'audio' => ['mimes' => WhatsAppAudioFormat::WHATSAPP_OUTBOUND_AUDIO_TYPES, 'max' => 16 * 1024 * 1024],
            'sticker' => ['mimes' => ['image/webp'], 'max' => 500 * 1024],
        ];
        if (! $file->isValid()) {
            throw new DomainException('Não foi possível receber o anexo para envio.');
        }

        if (! isset($allowed[$mediaType]) || $file->getSize() > $allowed[$mediaType]['max']) {
            throw new DomainException('Este formato ou tamanho não é aceito pelo WhatsApp.');
        }

        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents)) {
            throw new DomainException('Não foi possível ler o anexo.');
        }

        $preparedAudio = null;
        $mimeType = WhatsAppAudioFormat::canonicalize($file->getMimeType());
        $filename = (string) $file->getClientOriginalName();
        if ($mediaType === 'audio' && ($attributes['recording_source'] ?? null) === 'browser') {
            $preparedAudio = $this->audioNormalizer->normalize($contents, $mimeType, $filename);
            $contents = $preparedAudio->contents;
            $mimeType = $preparedAudio->mimeType;
            $filename = $preparedAudio->filename;
        }

        if (! in_array($mimeType, $allowed[$mediaType]['mimes'], true) || strlen($contents) > $allowed[$mediaType]['max']) {
            if ($mediaType === 'audio') {
                throw new DomainException('Este formato de audio nao e aceito pelo WhatsApp. Use OGG/Opus, MP3, M4A, AAC ou AMR.');
            }

            throw new DomainException('Este formato ou tamanho não é aceito pelo WhatsApp.');
        }

        return DB::transaction(function () use ($company, $conversation, $file, $mediaType, $caption, $attributes, $mimeType, $contents, $filename, $preparedAudio): WhatsAppMessageDelivery {
            $account = $this->defaultAccountFor($company);
            $recipientResolution = $this->resolveOutboundRecipient($conversation, (string) $conversation->whatsapp_identifier);
            $recipient = $recipientResolution['value'];
            $contents = $preparedAudio instanceof NormalizedWhatsAppAudio ? $contents : file_get_contents($file->getRealPath());
            if (! is_string($contents)) {
                throw new DomainException('Não foi possível ler o anexo.');
            }
            $upload = $this->provider->uploadMedia($contents, $mimeType, $filename, $account?->phone_number_id);
            if ($upload === null) {
                throw new DomainException('A Meta não aceitou o anexo.');
            }

            $message = Message::query()->create([
                'conversation_id' => $conversation->id, 'sender' => 'agent', 'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND,
                'sender_type' => $attributes['sender_type'] ?? 'human', 'content' => $caption, 'type' => $mediaType,
                'provider' => $this->provider->name(), 'external_recipient_id' => $recipient, 'delivery_status' => WhatsAppMessageDelivery::STATUS_QUEUED,
            ]);
            $path = 'whatsapp/'.$company->id.'/'.$conversation->id.'/'.Str::uuid()->toString().WhatsAppMediaFilename::extensionFor($mimeType);
            Storage::disk('local')->put($path, $contents);
            WhatsAppMediaFile::query()->create([
                'company_id' => $company->id, 'whatsapp_account_id' => $account?->id, 'message_id' => $message->id,
                'provider' => $this->provider->name(), 'provider_media_id' => $upload->mediaId, 'media_type' => $mediaType,
                'mime_type' => $mimeType, 'original_filename' => WhatsAppMediaFilename::forMedia($filename, $mimeType, $mediaType), 'size_bytes' => strlen($contents),
                'checksum' => hash('sha256', $contents), 'storage_disk' => 'local', 'file_path' => $path, 'status' => WhatsAppMediaFile::STATUS_STORED,
                'metadata' => ['direction' => 'outbound', 'client_filename' => $file->getClientOriginalName(), 'client_mime_type' => $file->getClientMimeType(), 'detected_mime_type' => $file->getMimeType(), 'received_size_bytes' => $file->getSize(), 'recording_source' => $attributes['recording_source'] ?? null, 'recording_mime_type' => $attributes['recording_mime_type'] ?? null, 'recording_requested_mime_type' => $attributes['recording_requested_mime_type'] ?? null, 'audio_normalization' => $preparedAudio instanceof NormalizedWhatsAppAudio ? ['action' => $preparedAudio->action, ...$preparedAudio->metadata] : null, ...$upload->safePayload],
            ]);
            $delivery = WhatsAppMessageDelivery::query()->create([
                'company_id' => $company->id, 'whatsapp_account_id' => $account?->id, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
                'provider' => $this->provider->name(), 'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND, 'message_type' => $mediaType,
                'recipient' => $recipient, 'status' => WhatsAppMessageDelivery::STATUS_QUEUED, 'content_preview' => Str::limit($caption, 120),
                'safe_payload' => ['provider' => $this->provider->name(), 'media_type' => $mediaType, 'recipient_source' => $recipientResolution['source']],
            ]);
            $result = $this->provider->sendMediaMessage(new OutgoingWhatsAppMessage($recipient, $caption, $account?->phone_number_id, metadata: ['delivery_id' => $delivery->id]), $upload->mediaId, $mediaType, $filename);
            $delivery->forceFill(['provider_message_id' => $result->providerMessageId, 'status' => $result->successful() ? WhatsAppMessageDelivery::STATUS_SENT : WhatsAppMessageDelivery::STATUS_FAILED, 'safe_payload' => array_merge((array) $delivery->safe_payload, $result->safePayload), 'sent_at' => $result->successful() ? now() : null, 'failed_at' => $result->successful() ? null : now(), 'error_message' => $result->errorMessage])->save();
            $message->forceFill(['external_message_id' => $result->providerMessageId, 'delivery_status' => $delivery->status, 'sent_at' => $delivery->sent_at, 'failed_at' => $delivery->failed_at, 'error_code' => $result->errorCode])->save();

            return $delivery->refresh();
        });
    }

    public function sendReaction(Company $company, Conversation $conversation, Message $target, string $emoji, ?int $userId = null): Conversation
    {
        $allowed = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉', '👏', '🔥', '✅', '💯'];
        if (! in_array($emoji, $allowed, true)) {
            throw new DomainException('Esta reação não está disponível.');
        }

        if ((int) $target->conversation_id !== (int) $conversation->id) {
            throw new DomainException('A mensagem não pertence a esta conversa.');
        }

        $target->loadMissing('conversation');
        if ((int) $target->conversation?->company_id !== (int) $company->id || ! $target->external_message_id) {
            throw new DomainException('Esta mensagem não pode receber reação.');
        }

        $recipient = $this->resolveOutboundRecipient($conversation, (string) $conversation->whatsapp_identifier)['value'];
        $account = $this->defaultAccountFor($company);
        $result = $this->provider->sendReactionMessage(
            new OutgoingWhatsAppMessage($recipient, '', $account?->phone_number_id),
            (string) $target->external_message_id,
            $emoji,
        );

        if (! $result->successful()) {
            throw new DomainException($result->errorMessage ?: 'Não foi possível enviar a reação.');
        }

        $metadata = (array) $target->metadata;
        $reactions = collect((array) ($metadata['whatsapp_reactions'] ?? []))
            ->reject(fn (mixed $reaction): bool => data_get($reaction, 'actor_key') === 'operator:'.($userId ?? 'current'))
            ->values()
            ->all();
        $reactions[] = [
            'actor_key' => 'operator:'.($userId ?? 'current'),
            'emoji' => $emoji,
            'source' => 'operator',
            'created_at' => now()->toIso8601String(),
        ];
        $metadata['whatsapp_reactions'] = $reactions;
        $target->forceFill(['metadata' => $metadata])->save();

        return $conversation->refresh();
    }

    public function retryTextMessage(Company $company, Message $message): WhatsAppMessageDelivery
    {
        return DB::transaction(function () use ($company, $message): WhatsAppMessageDelivery {
            $message = Message::query()
                ->with(['conversation', 'replyTo'])
                ->whereKey($message->id)
                ->lockForUpdate()
                ->firstOrFail();

            $conversation = $message->conversation;

            if (! $conversation instanceof Conversation || (int) $conversation->company_id !== (int) $company->id) {
                throw new DomainException('Mensagem não pertence ao restaurante atual.');
            }

            if ($message->direction !== WhatsAppMessageDelivery::DIRECTION_OUTBOUND || $message->delivery_status !== WhatsAppMessageDelivery::STATUS_FAILED) {
                throw new DomainException('Somente mensagens com falha podem ser reenviadas.');
            }

            $recipientResolution = $this->resolveOutboundRecipient(
                $conversation,
                (string) ($message->external_recipient_id ?: ''),
            );
            $recipient = $recipientResolution['value'];

            if ($recipient === '') {
                throw new DomainException('A conversa não possui telefone WhatsApp válido para reenvio.');
            }

            $account = $this->defaultAccountFor($company);

            $delivery = WhatsAppMessageDelivery::query()->create([
                'company_id' => $company->id,
                'whatsapp_account_id' => $account?->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'provider' => $this->provider->name(),
                'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND,
                'message_type' => 'text',
                'recipient' => $recipient,
                'status' => WhatsAppMessageDelivery::STATUS_QUEUED,
                'content_preview' => Str::limit((string) $message->content, 120),
                'safe_payload' => [
                    'provider' => $this->provider->name(),
                    'body_length' => strlen((string) $message->content),
                    'recipient_present' => true,
                    'recipient_source' => $recipientResolution['source'],
                    'recipient_digit_count' => $this->digitCount($recipient),
                    'recipient_suffix' => $this->identifierSuffix($recipient),
                    'retry_of_message_id' => $message->id,
                ],
            ]);

            $message->forceFill([
                'delivery_status' => WhatsAppMessageDelivery::STATUS_QUEUED,
                'failed_at' => null,
                'error_code' => null,
            ])->save();

            $result = $this->provider->sendTextMessage(new OutgoingWhatsAppMessage(
                to: $recipient,
                body: (string) $message->content,
                phoneNumberId: $account?->phone_number_id,
                replyToProviderMessageId: $message->replyTo?->external_message_id,
                metadata: ['delivery_id' => $delivery->id, 'retry_of_message_id' => $message->id],
            ));

            $delivery->forceFill([
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->successful()
                    ? WhatsAppMessageDelivery::STATUS_SENT
                    : WhatsAppMessageDelivery::STATUS_FAILED,
                'safe_payload' => array_merge((array) $delivery->safe_payload, $result->safePayload),
                'sent_at' => $result->successful() ? now() : null,
                'failed_at' => $result->successful() ? null : now(),
                'error_message' => $result->errorMessage,
            ])->save();

            $message->forceFill([
                'external_message_id' => $result->providerMessageId ?: $message->external_message_id,
                'external_recipient_id' => $recipient,
                'provider' => $this->provider->name(),
                'delivery_status' => $delivery->status,
                'sent_at' => $delivery->sent_at,
                'failed_at' => $delivery->failed_at,
                'error_code' => $result->errorCode,
            ])->save();

            $conversation->forceFill([
                'last_business_message_at' => now(),
                'last_message_at' => now(),
            ])->save();

            return $delivery->refresh();
        });
    }

    public function markConversationAsRead(Company $company, Conversation $conversation): Conversation
    {
        if ((int) $conversation->company_id !== (int) $company->id) {
            throw new DomainException('Conversa não pertence ao restaurante atual.');
        }

        $unreadMessages = DB::transaction(function () use ($conversation): array {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $messages = Message::query()
                ->where('conversation_id', $lockedConversation->id)
                ->where('direction', WhatsAppMessageDelivery::DIRECTION_INBOUND)
                ->whereNull('read_at')
                ->get(['id', 'external_message_id']);

            if ($messages->isNotEmpty()) {
                Message::query()
                    ->whereKey($messages->modelKeys())
                    ->update(['read_at' => now()]);
            }

            $lockedConversation->forceFill(['unread_count' => 0])->save();

            return $messages
                ->pluck('external_message_id')
                ->filter(fn ($messageId): bool => is_string($messageId) && $messageId !== '')
                ->values()
                ->all();
        });

        $account = $this->defaultAccountFor($company);

        foreach ($unreadMessages as $messageId) {
            try {
                $result = $this->provider->markMessageAsRead($messageId, $account?->phone_number_id);

                if (! $result->successful()) {
                    Log::warning('WhatsApp read receipt failed.', [
                        'conversation_id' => $conversation->id,
                        'message_id_suffix' => $this->identifierSuffix($messageId),
                        'provider' => $result->provider,
                        'error_code' => $result->errorCode,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('WhatsApp read receipt could not be sent.', [
                    'conversation_id' => $conversation->id,
                    'message_id_suffix' => $this->identifierSuffix($messageId),
                    'provider' => $this->provider->name(),
                    'error_class' => $exception::class,
                ]);
            }
        }

        return $conversation->refresh();
    }

    private function persistIncomingMessage(IncomingWhatsAppMessage $incomingMessage, WhatsAppWebhookEvent $event): void
    {
        $account = $this->resolveAccountForIncoming($incomingMessage, $event);

        if ($account === null) {
            throw new DomainException('whatsapp_account_not_resolved');
        }

        if ($incomingMessage->providerMessageId !== null && Message::query()
            ->where('provider', $incomingMessage->provider)
            ->where('external_message_id', $incomingMessage->providerMessageId)
            ->exists()) {
            return;
        }

        $company = $account->company()->firstOrFail();
        $this->inboundTrace->record($event, 'company_resolved', [
            'account_id' => $account->id,
            'company_id' => $company->id,
        ]);
        $customer = $this->resolveCustomerForIncoming($company, $incomingMessage);
        $this->inboundTrace->record($event, 'customer_resolved', [
            'company_id' => $company->id,
            'customer_id' => $customer->id,
        ]);
        $conversation = $this->resolveOpenConversation($account->company_id, $customer->id, $incomingMessage);
        $this->inboundTrace->record($event, 'conversation_resolved', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
        ]);

        if ($incomingMessage->messageType === 'message_revoked') {
            $this->applyIncomingRevocation($conversation, $incomingMessage);

            return;
        }

        if ($incomingMessage->messageType === 'reaction') {
            $this->applyIncomingReaction($conversation, $incomingMessage);

            return;
        }

        $expectedAutomationVersion = (int) ($conversation->automation_version ?? 0);
        $content = $incomingMessage->text ?: $this->placeholderForMessageType($incomingMessage->messageType);
        $replyToMessageId = $incomingMessage->replyToProviderMessageId
            ? Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('external_message_id', $incomingMessage->replyToProviderMessageId)
                ->value('id')
            : null;

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'reply_to_message_id' => $replyToMessageId,
            'sender' => 'customer',
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'sender_type' => 'customer',
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
        $this->inboundTrace->record($event, 'message_persisted', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'message_id_suffix' => $this->identifierSuffix($incomingMessage->providerMessageId),
            'message_type' => $incomingMessage->messageType,
        ]);

        WhatsAppMessageDelivery::query()->firstOrCreate(
            [
                'provider' => $incomingMessage->provider,
                'provider_message_id' => $incomingMessage->providerMessageId,
                'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            ],
            [
                'company_id' => $account->company_id,
                'whatsapp_account_id' => $account->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'message_type' => $incomingMessage->messageType,
                'recipient' => $incomingMessage->to,
                'sender' => $incomingMessage->from,
                'status' => WhatsAppMessageDelivery::STATUS_RECEIVED,
                'content_preview' => Str::limit($content, 120),
                'safe_payload' => $incomingMessage->safeMetadata,
            ],
        );

        $media = $this->mediaStorage->storeIncomingMedia($company, $account, $message, $event, $incomingMessage);
        $this->updateConversationAfterInboundMessage($conversation, $message, $incomingMessage);

        if ($this->customerAskedForHuman($content)) {
            $this->alerts->open(
                company: $company,
                type: ConversationAlert::TYPE_HUMAN_REQUESTED,
                severity: ConversationAlert::SEVERITY_WARNING,
                title: 'Cliente pediu atendente',
                message: 'A conversa deve ser acompanhada manualmente.',
                conversation: $conversation,
                messageModel: $message,
                deduplicationKey: 'human-request:'.$message->id,
            );
        }

        $paymentProofCandidate = $this->paymentProofClassifier->classify($conversation, $message, $media);
        if ($paymentProofCandidate !== null) {
            $this->handlePossiblePaymentProof($company, $conversation, $message, $media, $paymentProofCandidate);
        }

        $this->ai->considerIncomingMessage($company, $conversation->refresh(), $message, $expectedAutomationVersion);

        $account->forceFill([
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'last_webhook_at' => now(),
            'connected_at' => $account->connected_at ?? now(),
        ])->save();
    }

    private function applyIncomingReaction(Conversation $conversation, IncomingWhatsAppMessage $incomingMessage): void
    {
        $targetProviderMessageId = data_get($incomingMessage->rawPayload, 'reaction.message_id');
        $emoji = (string) data_get($incomingMessage->rawPayload, 'reaction.emoji', '');
        if (! is_string($targetProviderMessageId) || $targetProviderMessageId === '') {
            return;
        }

        $target = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('external_message_id', $targetProviderMessageId)
            ->first();
        if (! $target) {
            return;
        }

        $metadata = (array) $target->metadata;
        $actorKey = 'customer:'.($incomingMessage->from ?? 'unknown');
        $reactions = collect((array) ($metadata['whatsapp_reactions'] ?? []))
            ->reject(fn (mixed $reaction): bool => data_get($reaction, 'actor_key') === $actorKey)
            ->values()
            ->all();
        if ($emoji !== '') {
            $reactions[] = [
                'actor_key' => $actorKey,
                'emoji' => $emoji,
                'source' => 'customer',
                'created_at' => now()->toIso8601String(),
            ];
        }
        $metadata['whatsapp_reactions'] = $reactions;
        $target->forceFill(['metadata' => $metadata])->save();
    }

    private function applyIncomingRevocation(Conversation $conversation, IncomingWhatsAppMessage $incomingMessage): void
    {
        $targetProviderMessageId = data_get($incomingMessage->rawPayload, 'revoked.message_id')
            ?? data_get($incomingMessage->rawPayload, 'message_deleted.id')
            ?? data_get($incomingMessage->rawPayload, 'deleted.message_id')
            ?? data_get($incomingMessage->rawPayload, 'id');

        if (! is_string($targetProviderMessageId) || $targetProviderMessageId === '') {
            return;
        }

        $target = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('external_message_id', $targetProviderMessageId)
            ->first();
        if (! $target) {
            return;
        }

        $metadata = (array) $target->metadata;
        $metadata['message_revoked'] = true;
        $metadata['revoked_at'] = now()->toIso8601String();
        $target->forceFill(['content' => null, 'metadata' => $metadata])->save();
    }

    private function defaultAccountFor(Company $company): ?WhatsAppAccount
    {
        if ($this->provider->name() === WhatsAppAccount::PROVIDER_META_CLOUD) {
            $account = $this->configuredMetaAccountFor($company);

            if ($account instanceof WhatsAppAccount) {
                return $account;
            }
        }

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
            $account = WhatsAppAccount::query()
                ->where('provider', $this->provider->name())
                ->where('phone_number_id', $message->providerAccountId)
                ->first();

            if ($account instanceof WhatsAppAccount) {
                return $account;
            }

            return $this->configuredMetaAccountForDefaultCompany($message->providerAccountId);
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

            $account = $this->configuredMetaAccountForDefaultCompany($phoneNumberId);

            if ($account instanceof WhatsAppAccount) {
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

        return $this->resolveOpenConversation($company->id, $customer->id, null, $to);
    }

    private function resolveCustomerForIncoming(Company $company, IncomingWhatsAppMessage $message): Customer
    {
        return $this->resolveCustomerForPhone($company, (string) $message->from, $message->senderName, true);
    }

    private function resolveCustomerForPhone(Company $company, string $phone, ?string $name = null, bool $fromWhatsApp = false): Customer
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $phoneResolution = $fromWhatsApp ? $this->phoneResolver->resolve($normalizedPhone) : null;
        $canonicalPhone = $phoneResolution['canonical_phone'] ?? $normalizedPhone;

        $customer = Customer::query()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($normalizedPhone, $canonicalPhone): void {
                $query->where('whatsapp_id', $normalizedPhone)
                    ->orWhere('phone', $normalizedPhone);

                if ($canonicalPhone !== null && $canonicalPhone !== $normalizedPhone) {
                    $query->orWhere('phone', $canonicalPhone);
                }
            })
            ->first();

        if ($customer instanceof Customer) {
            $updates = [];

            if ($fromWhatsApp && ! $customer->whatsapp_id) {
                $updates['whatsapp_id'] = $normalizedPhone;
            }

            if ($fromWhatsApp && ! $customer->phone && $canonicalPhone !== null) {
                $updates['phone'] = $canonicalPhone;
            }

            if ($fromWhatsApp && $name && ! $customer->whatsapp_profile_name) {
                $updates['whatsapp_profile_name'] = $name;
            }

            if ($fromWhatsApp) {
                $updates['last_whatsapp_at'] = now();
            }

            if ($updates !== []) {
                $customer->forceFill($updates)->save();
            }

            return $customer->refresh();
        }

        return Customer::query()->create(
            [
                'company_id' => $company->id,
                'name' => $name ?: 'Cliente WhatsApp',
                'phone' => $canonicalPhone,
                'whatsapp_id' => $fromWhatsApp ? $normalizedPhone : null,
                'whatsapp_profile_name' => $fromWhatsApp ? $name : null,
                'last_whatsapp_at' => $fromWhatsApp ? now() : null,
                'source_channel' => $fromWhatsApp ? 'whatsapp' : 'manual',
            ],
        );
    }

    private function resolveOpenConversation(
        int $companyId,
        int $customerId,
        ?IncomingWhatsAppMessage $incomingMessage = null,
        ?string $identifier = null,
    ): Conversation {
        $whatsappIdentifier = $identifier !== null
            ? $this->normalizePhone($identifier)
            : ($incomingMessage?->from !== null ? $this->normalizePhone($incomingMessage->from) : null);

        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('channel', 'whatsapp')
            ->where('status', 'open')
            ->first();

        if (! $conversation instanceof Conversation) {
            $conversation = Conversation::query()->create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'channel' => 'whatsapp',
                'status' => 'open',
                'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
                'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
                'whatsapp_identifier' => $whatsappIdentifier,
                'whatsapp_profile_name' => $incomingMessage?->senderName,
                'started_at' => now(),
            ]);

            $this->alerts->open(
                company: $conversation->company()->firstOrFail(),
                type: ConversationAlert::TYPE_NEW_CONVERSATION,
                severity: ConversationAlert::SEVERITY_INFO,
                title: 'Nova conversa no WhatsApp',
                message: 'Um cliente iniciou atendimento pelo WhatsApp.',
                conversation: $conversation,
                deduplicationKey: 'new-conversation:'.$conversation->id,
            );
        } elseif ($whatsappIdentifier !== null || $incomingMessage?->senderName !== null) {
            $conversation->forceFill([
                'whatsapp_identifier' => $conversation->whatsapp_identifier ?: $whatsappIdentifier,
                'whatsapp_profile_name' => $conversation->whatsapp_profile_name ?: $incomingMessage?->senderName,
            ])->save();
        }

        return $conversation->refresh();
    }

    private function updateConversationAfterInboundMessage(
        Conversation $conversation,
        Message $message,
        IncomingWhatsAppMessage $incomingMessage,
    ): void {
        $conversation->forceFill([
            'whatsapp_identifier' => $conversation->whatsapp_identifier ?: $incomingMessage->from,
            'whatsapp_profile_name' => $conversation->whatsapp_profile_name ?: $incomingMessage->senderName,
            'last_message_at' => $message->created_at ?? now(),
            'last_customer_message_at' => $message->created_at ?? now(),
            'unread_count' => (int) ($conversation->unread_count ?? 0) + 1,
        ])->save();
    }

    private function placeholderForMessageType(string $type): string
    {
        return match ($type) {
            'image' => '[imagem recebida]',
            'document' => '[documento recebido]',
            'audio' => '[audio recebido]',
            'video' => '[video recebido]',
            'location' => '[localizacao recebida]',
            'interactive' => '[resposta interativa recebida]',
            default => '['.$type.' recebido]',
        };
    }

    private function customerAskedForHuman(string $content): bool
    {
        $normalized = Str::of($content)->ascii()->lower()->toString();

        return str_contains($normalized, 'atendente')
            || str_contains($normalized, 'humano')
            || str_contains($normalized, 'larissa')
            || str_contains($normalized, 'beatriz');
    }

    /** @param array{order: Order, confidence: string, signals: list<string>} $candidate */
    private function handlePossiblePaymentProof(Company $company, Conversation $conversation, Message $message, ?WhatsAppMediaFile $media, array $candidate): void
    {
        $order = $candidate['order'];

        if ((int) ($conversation->active_order_id ?? 0) !== (int) $order->id) {
            $conversation->forceFill(['active_order_id' => $order->id])->save();
        }

        $payment = $order->payments()
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_AWAITING_PROOF, Payment::STATUS_PROOF_RECEIVED])
            ->latest('id')
            ->first();

        if (! $payment instanceof Payment) {
            $payment = $this->payments->recordPayment($order, [
                'method' => Payment::METHOD_PIX,
                'status' => Payment::STATUS_AWAITING_PROOF,
                'provider' => Payment::PROVIDER_MANUAL,
                'metadata' => ['source' => 'whatsapp_conversation'],
            ]);
        }

        $proof = $this->payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'storage_disk' => $media?->storage_disk,
            'file_path' => $media?->file_path,
            'original_filename' => $media?->original_filename,
            'mime_type' => $media?->mime_type,
            'status' => PaymentProof::STATUS_RECEIVED,
            'metadata' => [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'whatsapp_media_file_id' => $media?->id,
            ],
        ]);

        $this->alerts->open(
            company: $company,
            type: ConversationAlert::TYPE_PAYMENT_PROOF_RECEIVED,
            severity: ConversationAlert::SEVERITY_CRITICAL,
            title: 'Comprovante aguardando revisao',
            message: 'Confira o comprovante antes de confirmar qualquer pagamento.',
            conversation: $conversation,
            order: $order,
            messageModel: $message,
            payment: $payment,
            paymentProof: $proof,
            deduplicationKey: 'payment-proof:'.$proof->id,
            metadata: ['classification' => 'deterministic_payment_proof_candidate', 'confidence' => $candidate['confidence'], 'signals' => $candidate['signals']],
        );

        if ($conversation->automation_mode !== Conversation::AUTOMATION_MODE_MANUAL) {
            $recipient = $conversation->whatsapp_identifier ?: $conversation->customer()->value('whatsapp_id') ?: $conversation->customer()->value('phone');

            if (is_string($recipient) && trim($recipient) !== '') {
                $this->sendTextMessage(
                    $company,
                    $recipient,
                    'Recebemos seu comprovante. Vamos conferir o pagamento e avisaremos assim que ele for confirmado.',
                    [
                        'conversation' => $conversation,
                        'sender_type' => 'ai',
                    ],
                );
            }
        }
    }

    private function processStatusesFromPayload(array $payload, WhatsAppWebhookEvent $event): int
    {
        $count = 0;

        foreach ($this->statusRows($payload) as $statusRow) {
            $messageId = $statusRow['id'] ?? null;
            $status = $statusRow['status'] ?? null;

            if (! is_string($messageId) || ! is_string($status)) {
                continue;
            }

            $delivery = WhatsAppMessageDelivery::query()
                ->where('provider', $event->provider)
                ->where('provider_message_id', $messageId)
                ->first();

            if (! $delivery instanceof WhatsAppMessageDelivery) {
                continue;
            }

            $timestamp = isset($statusRow['timestamp'])
                ? now()->setTimestamp((int) $statusRow['timestamp'])
                : now();

            $fields = ['status' => $this->mapProviderStatus($status)];

            if ($fields['status'] === WhatsAppMessageDelivery::STATUS_DELIVERED) {
                $fields['delivered_at'] = $timestamp;
            } elseif ($fields['status'] === WhatsAppMessageDelivery::STATUS_READ) {
                $fields['read_at'] = $timestamp;
            } elseif ($fields['status'] === WhatsAppMessageDelivery::STATUS_FAILED) {
                $providerError = $this->errors->providerRejection(
                    400,
                    is_array($statusRow['errors'][0] ?? null) ? $statusRow['errors'][0] : [],
                );
                $fields['failed_at'] = $timestamp;
                $fields['error_message'] = $providerError['message'];
                $fields['safe_payload'] = array_merge($delivery->safe_payload ?? [], [
                    'error_code' => $providerError['code'],
                    ...$providerError['safe_details'],
                ]);
            }

            $delivery->forceFill($fields)->save();

            if ($delivery->message) {
                $message = $delivery->message;
                $message->forceFill([
                    'delivery_status' => $fields['status'],
                    'delivered_at' => $fields['delivered_at'] ?? $message->delivered_at,
                    'read_at' => $fields['read_at'] ?? $message->read_at,
                    'failed_at' => $fields['failed_at'] ?? $message->failed_at,
                    'error_code' => $fields['status'] === WhatsAppMessageDelivery::STATUS_FAILED
                        ? data_get($fields, 'safe_payload.error_code')
                        : null,
                ])->save();

                $company = $delivery->company;
                $alertKey = 'message-send-failed:'.$message->id;

                if ($fields['status'] === WhatsAppMessageDelivery::STATUS_FAILED && $company instanceof Company) {
                    $this->alerts->open(
                        company: $company,
                        type: ConversationAlert::TYPE_MESSAGE_SEND_FAILED,
                        severity: ConversationAlert::SEVERITY_WARNING,
                        title: 'Falha ao enviar mensagem',
                        message: $fields['error_message'],
                        conversation: $delivery->conversation,
                        messageModel: $message,
                        deduplicationKey: $alertKey,
                        metadata: [
                            'delivery_id' => $delivery->id,
                            'error_code' => data_get($fields, 'safe_payload.error_code'),
                        ],
                    );
                } elseif (in_array($fields['status'], [
                    WhatsAppMessageDelivery::STATUS_SENT,
                    WhatsAppMessageDelivery::STATUS_DELIVERED,
                    WhatsAppMessageDelivery::STATUS_READ,
                ], true)) {
                    ConversationAlert::query()
                        ->where('company_id', $delivery->company_id)
                        ->where('deduplication_key', $alertKey)
                        ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
                        ->get()
                        ->each(fn (ConversationAlert $alert) => $this->alerts->resolve($alert));
                }
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusRows(array $payload): array
    {
        $rows = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach (($change['value']['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        $rows[] = $status;
                    }
                }
            }
        }

        return $rows;
    }

    private function mapProviderStatus(string $status): string
    {
        return match ($status) {
            'sent' => WhatsAppMessageDelivery::STATUS_SENT,
            'delivered' => WhatsAppMessageDelivery::STATUS_DELIVERED,
            'read' => WhatsAppMessageDelivery::STATUS_READ,
            'failed' => WhatsAppMessageDelivery::STATUS_FAILED,
            default => $status,
        };
    }

    private function deduplicationKey(array $payload): ?string
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if (! empty($value['messages'][0]['id'])) {
                    return 'message:'.(string) $value['messages'][0]['id'];
                }

                if (! empty($value['statuses'][0]['id']) && ! empty($value['statuses'][0]['status'])) {
                    return 'status:'.(string) $value['statuses'][0]['id'].':'.(string) $value['statuses'][0]['status'];
                }
            }
        }

        if (! empty($payload['messages'][0]['id'])) {
            return 'message:'.(string) $payload['messages'][0]['id'];
        }

        return null;
    }

    private function resolveOpenConversationLegacy(int $companyId, int $customerId): Conversation
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

    private function normalizeWhatsAppRecipient(string $phone): string
    {
        $digits = $this->normalizePhone($phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (! str_starts_with($digits, '55') && in_array(strlen($digits), [10, 11], true)) {
            return '55'.$digits;
        }

        return $digits;
    }

    /**
     * Keep the provider identity separate from a manually corrected phone
     * number used for outbound delivery.
     *
     * @return array{value: string, source: string}
     */
    private function resolveOutboundRecipient(Conversation $conversation, string $fallback): array
    {
        $customer = $conversation->relationLoaded('customer')
            ? $conversation->customer
            : $conversation->customer()->first();
        $providerIdentity = (string) ($conversation->whatsapp_identifier ?: $customer?->whatsapp_id ?: $fallback);
        $resolution = $this->phoneResolver->resolve($providerIdentity, $customer?->phone);
        $canonicalPhone = $resolution['canonical_phone'];

        if (is_string($canonicalPhone) && trim($canonicalPhone) !== '') {
            return [
                'value' => $this->normalizeWhatsAppRecipient($canonicalPhone),
                'source' => $resolution['source'],
            ];
        }

        return [
            'value' => $this->normalizeWhatsAppRecipient((string) $providerIdentity),
            'source' => $conversation->whatsapp_identifier !== null
                ? 'conversation_identifier'
                : ($customer?->whatsapp_id ? 'provider_wa_id' : 'fallback'),
        ];
    }

    private function digitCount(string $value): int
    {
        return strlen($this->normalizePhone($value));
    }

    private function markDefaultAccountWebhookVerified(): void
    {
        $account = WhatsAppAccount::query()
            ->where('provider', $this->provider->name())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first()
            ?: $this->configuredMetaAccountForDefaultCompany();

        if ($account === null) {
            return;
        }

        $account->forceFill([
            'webhook_verified_at' => now(),
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'connected_at' => $account->connected_at ?? now(),
        ])->save();
    }

    private function configuredMetaAccountFor(Company $company): ?WhatsAppAccount
    {
        $phoneNumberId = trim((string) config('chatbotcrm.whatsapp.meta.phone_number_id', ''));

        if ($this->provider->name() !== WhatsAppAccount::PROVIDER_META_CLOUD || $phoneNumberId === '') {
            return null;
        }

        $account = WhatsAppAccount::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
                'phone_number_id' => $phoneNumberId,
            ],
            [
                'name' => 'WhatsApp Meta Cloud',
                'business_account_id' => config('chatbotcrm.whatsapp.meta.business_account_id'),
                'status' => WhatsAppAccount::STATUS_CONNECTED,
                'is_default' => true,
                'connected_at' => now(),
                'settings' => [
                    'source' => 'config',
                    'api_version' => config('chatbotcrm.whatsapp.meta.api_version'),
                ],
            ],
        );

        WhatsAppAccount::query()
            ->where('company_id', $company->id)
            ->where('provider', WhatsAppAccount::PROVIDER_META_CLOUD)
            ->whereKeyNot($account->id)
            ->update(['is_default' => false]);

        return $account->refresh();
    }

    private function configuredMetaAccountForDefaultCompany(?string $payloadPhoneNumberId = null): ?WhatsAppAccount
    {
        $configuredPhoneNumberId = trim((string) config('chatbotcrm.whatsapp.meta.phone_number_id', ''));

        if ($payloadPhoneNumberId !== null && $configuredPhoneNumberId !== '' && $payloadPhoneNumberId !== $configuredPhoneNumberId) {
            return null;
        }

        $company = Company::query()->orderBy('id')->first();

        return $company instanceof Company ? $this->configuredMetaAccountFor($company) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|int|string|null>
     */
    private function payloadStructureTrace(array $payload): array
    {
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
        $changeCount = 0;
        $messagesCount = 0;
        $statusesCount = 0;
        $messagesPresent = false;
        $statusesPresent = false;
        $metadataPhoneNumberIdPresent = false;

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $changeCount++;
                $value = $change['value'] ?? null;

                if (! is_array($value)) {
                    continue;
                }

                $messages = $value['messages'] ?? null;
                $statuses = $value['statuses'] ?? null;
                $messagesPresent = $messagesPresent || is_array($messages);
                $statusesPresent = $statusesPresent || is_array($statuses);
                $messagesCount += is_array($messages) ? count($messages) : 0;
                $statusesCount += is_array($statuses) ? count($statuses) : 0;
                $metadataPhoneNumberIdPresent = $metadataPhoneNumberIdPresent
                    || is_scalar(data_get($value, 'metadata.phone_number_id'));
            }
        }

        return [
            'object' => is_scalar($payload['object'] ?? null) ? (string) $payload['object'] : null,
            'entry_count' => count($entries),
            'change_count' => $changeCount,
            'messages_present' => $messagesPresent,
            'messages_count' => $messagesCount,
            'statuses_present' => $statusesPresent,
            'statuses_count' => $statusesCount,
            'metadata_phone_number_id_present' => $metadataPhoneNumberIdPresent,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{stage: string, error_code: string}>
     */
    private function payloadStructureWarnings(array $payload): array
    {
        $warnings = [];
        $object = $payload['object'] ?? null;
        $entries = $payload['entry'] ?? null;

        if ($object !== 'whatsapp_business_account') {
            $warnings[] = [
                'stage' => 'unsupported_object',
                'error_code' => 'whatsapp_unsupported_object',
            ];
        }

        if (! is_array($entries) || $entries === []) {
            $warnings[] = [
                'stage' => 'entry_missing',
                'error_code' => 'whatsapp_entry_missing',
            ];

            return $warnings;
        }

        $hasChanges = false;

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                continue;
            }

            $hasChanges = true;

            foreach ($entry['changes'] as $change) {
                if (! is_array($change)) {
                    continue;
                }

                if (! array_key_exists('value', $change) || ! is_array($change['value'])) {
                    $warnings[] = [
                        'stage' => 'value_missing',
                        'error_code' => 'whatsapp_change_value_missing',
                    ];
                }

                if (isset($change['field']) && $change['field'] !== 'messages') {
                    $warnings[] = [
                        'stage' => 'unsupported_field',
                        'error_code' => 'whatsapp_unsupported_field',
                    ];
                }
            }
        }

        if (! $hasChanges) {
            $warnings[] = [
                'stage' => 'changes_missing',
                'error_code' => 'whatsapp_changes_missing',
            ];
        }

        if ($this->providerEventId($payload) === null) {
            $warnings[] = [
                'stage' => 'provider_event_id_missing',
                'error_code' => 'whatsapp_provider_event_id_missing',
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private function traceContextForPayload(array $payload): array
    {
        $change = data_get($payload, 'entry.0.changes.0', []);
        $value = is_array($change) ? ($change['value'] ?? []) : [];
        $message = is_array($value) ? data_get($value, 'messages.0', []) : [];
        $status = is_array($value) ? data_get($value, 'statuses.0', []) : [];

        return [
            'field' => is_array($change) ? ($change['field'] ?? null) : null,
            'message_type' => is_array($message) ? ($message['type'] ?? null) : null,
            'message_id_suffix' => $this->identifierSuffix(
                is_array($message) ? ($message['id'] ?? null) : (is_array($status) ? ($status['id'] ?? null) : null),
            ),
            'phone_number_id_suffix' => $this->identifierSuffix(
                is_array($value) ? data_get($value, 'metadata.phone_number_id') : null,
            ),
        ];
    }

    private function identifierSuffix(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return '...'.substr((string) $value, -6);
    }

    private function webhookProcessingErrorCode(Throwable $exception): string
    {
        if ($exception instanceof DomainException && $exception->getMessage() === 'whatsapp_account_not_resolved') {
            return 'whatsapp_account_not_resolved';
        }

        return 'whatsapp_webhook_processing_failed';
    }
}
