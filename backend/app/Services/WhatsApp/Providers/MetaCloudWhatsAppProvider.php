<?php

namespace App\Services\WhatsApp\Providers;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Data\WhatsApp\WhatsAppConnectionStatus;
use App\Data\WhatsApp\WhatsAppDownloadedMedia;
use App\Data\WhatsApp\WhatsAppMediaUploadResult;
use App\Data\WhatsApp\WhatsAppSendResult;
use App\Services\WhatsApp\MetaWebhookPayloadParser;
use App\Services\WhatsApp\WhatsAppErrorClassifier;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetaCloudWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(
        private readonly MetaWebhookPayloadParser $parser,
        private readonly WhatsAppErrorClassifier $errors,
    ) {}

    public function name(): string
    {
        return 'meta_cloud';
    }

    public function isConfigured(): bool
    {
        return $this->token() !== ''
            && $this->phoneNumberId() !== ''
            && $this->verifyToken() !== '';
    }

    public function connectionStatus(): WhatsAppConnectionStatus
    {
        return new WhatsAppConnectionStatus(
            provider: $this->name(),
            configured: $this->isConfigured(),
            status: $this->isConfigured() ? 'configured' : 'missing_configuration',
            details: [
                'phone_number_id_present' => $this->phoneNumberId() !== '',
                'business_account_id_present' => $this->businessAccountId() !== '',
                'verify_token_present' => $this->verifyToken() !== '',
                'token_present' => $this->token() !== '',
                'api_version' => $this->apiVersion(),
                'ca_bundle_configured' => $this->caBundle() !== '',
                'ca_bundle_readable' => $this->caBundleIsReadable(),
            ],
        );
    }

    public function diagnoseConnectivity(): array
    {
        if (! $this->isConfigured()) {
            $error = $this->errors->configurationMissing();

            return [
                'status' => 'failed',
                'http_status' => null,
                'error_code' => $error['code'],
                'message' => $error['message'],
                'external_api_called' => false,
            ];
        }

        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$this->phoneNumberId();

        try {
            $response = $this->request()
                ->timeout(12)
                ->get($url, ['fields' => 'id']);
        } catch (Throwable $exception) {
            $error = $this->errors->networkFailure($exception);

            return [
                'status' => 'failed',
                'http_status' => null,
                'error_code' => $error['code'],
                'message' => $error['message'],
                'external_api_called' => true,
                ...$error['safe_details'],
            ];
        }

        if ($response->successful()) {
            return [
                'status' => 'available',
                'http_status' => $response->status(),
                'error_code' => null,
                'external_api_called' => true,
            ];
        }

        $json = $response->json();
        $errorPayload = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $error = $this->errors->providerRejection($response->status(), $errorPayload);

        return [
            'status' => 'failed',
            'http_status' => $response->status(),
            'error_code' => $error['code'],
            'message' => $error['message'],
            'external_api_called' => true,
            ...$error['safe_details'],
        ];
    }

    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string
    {
        if ($mode !== 'subscribe' || $challenge === null || $this->verifyToken() === '') {
            return null;
        }

        return hash_equals($this->verifyToken(), (string) $token) ? $challenge : null;
    }

    public function parseWebhookPayload(array $payload): array
    {
        return $this->parser->parse($payload, $this->name());
    }

    public function sendTextMessage(OutgoingWhatsAppMessage $message): WhatsAppSendResult
    {
        if (! $this->isConfigured()) {
            $error = $this->errors->configurationMissing();

            return new WhatsAppSendResult(
                provider: $this->name(),
                status: 'failed',
                errorMessage: $error['message'],
                errorCode: $error['code'],
                safePayload: ['configured' => false, 'error_code' => $error['code']],
            );
        }

        $phoneNumberId = $message->phoneNumberId ?: $this->phoneNumberId();
        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$phoneNumberId.'/messages';

        try {
            $response = $this->request()
                ->asJson()
                ->timeout(12)
                ->retry(1, 250, throw: false)
                ->post($url, array_filter([
                    'messaging_product' => 'whatsapp',
                    'to' => $message->to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message->body,
                    ],
                    'context' => $message->replyToProviderMessageId !== null
                        ? ['message_id' => $message->replyToProviderMessageId]
                        : null,
                ]));
        } catch (Throwable $exception) {
            $error = $this->errors->networkFailure($exception);

            return new WhatsAppSendResult(
                provider: $this->name(),
                status: 'failed',
                errorMessage: $error['message'],
                errorCode: $error['code'],
                safePayload: [
                    'http_status' => null,
                    'recipient_present' => $message->to !== '',
                    'body_length' => strlen($message->body),
                    'provider_message_id_present' => false,
                    'phone_number_id_present' => $phoneNumberId !== '',
                    'connection_error' => true,
                    'error_code' => $error['code'],
                    ...$error['safe_details'],
                ],
            );
        }

        $json = $response->json();
        $providerMessageId = is_array($json) ? ($json['messages'][0]['id'] ?? null) : null;
        $providerError = is_array($json) && isset($json['error']) && is_array($json['error'])
            ? $this->errors->providerRejection($response->status(), $json['error'])
            : $this->errors->providerRejection($response->status(), []);

        return new WhatsAppSendResult(
            provider: $this->name(),
            status: $response->successful() ? 'sent' : 'failed',
            providerMessageId: $providerMessageId,
            errorMessage: $response->successful() ? null : $providerError['message'],
            errorCode: $response->successful() ? null : $providerError['code'],
            safePayload: [
                'http_status' => $response->status(),
                'recipient_present' => $message->to !== '',
                'body_length' => strlen($message->body),
                'reply_context_present' => $message->replyToProviderMessageId !== null,
                'provider_message_id_present' => $providerMessageId !== null,
                'phone_number_id_present' => $phoneNumberId !== '',
                'error_code' => $response->successful() ? null : $providerError['code'],
                ...($response->successful() ? [] : $providerError['safe_details']),
            ],
        );
    }

    public function uploadMedia(string $contents, string $mimeType, string $filename, ?string $phoneNumberId = null): ?WhatsAppMediaUploadResult
    {
        $resolvedPhoneNumberId = $phoneNumberId ?: $this->phoneNumberId();
        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$resolvedPhoneNumberId.'/media';

        try {
            $response = $this->request()->timeout(20)->attach('file', $contents, $filename, ['Content-Type' => $mimeType])->post($url, [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);
        } catch (Throwable) {
            return null;
        }

        $mediaId = $response->json('id');

        return $response->successful() && is_string($mediaId) && $mediaId !== ''
            ? new WhatsAppMediaUploadResult($mediaId, ['http_status' => $response->status(), 'mime_type' => $mimeType, 'size_bytes' => strlen($contents)])
            : null;
    }

    public function sendMediaMessage(OutgoingWhatsAppMessage $message, string $mediaId, string $mediaType, ?string $filename = null): WhatsAppSendResult
    {
        $phoneNumberId = $message->phoneNumberId ?: $this->phoneNumberId();
        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$phoneNumberId.'/messages';
        $media = ['id' => $mediaId];
        if ($message->body !== '' && in_array($mediaType, ['image', 'video', 'document'], true)) {
            $media['caption'] = $message->body;
        }
        if ($mediaType === 'document' && $filename) {
            $media['filename'] = $filename;
        }
        try {
            $response = $this->request()->asJson()->timeout(20)->post($url, [
                'messaging_product' => 'whatsapp', 'to' => $message->to, 'type' => $mediaType, $mediaType => $media,
            ]);
        } catch (Throwable $exception) {
            $error = $this->errors->networkFailure($exception);

            return new WhatsAppSendResult($this->name(), 'failed', null, $error['message'], $error['code'], $error['safe_details']);
        }
        $id = $response->json('messages.0.id');
        $providerError = $response->json('error');
        $error = is_array($providerError) ? $this->errors->providerRejection($response->status(), $providerError) : null;

        return new WhatsAppSendResult($this->name(), $response->successful() ? 'sent' : 'failed', is_string($id) ? $id : null, $error['message'] ?? null, $error['code'] ?? null, ['http_status' => $response->status(), 'media_id_present' => true, ...($error['safe_details'] ?? [])]);
    }

    public function sendReactionMessage(OutgoingWhatsAppMessage $message, string $targetMessageId, string $emoji): WhatsAppSendResult
    {
        $phoneNumberId = $message->phoneNumberId ?: $this->phoneNumberId();
        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$phoneNumberId.'/messages';

        try {
            $response = $this->request()->asJson()->timeout(20)->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $message->to,
                'type' => 'reaction',
                'reaction' => ['message_id' => $targetMessageId, 'emoji' => $emoji],
            ]);
        } catch (Throwable $exception) {
            $error = $this->errors->networkFailure($exception);

            return new WhatsAppSendResult($this->name(), 'failed', null, $error['message'], $error['code'], $error['safe_details']);
        }

        $id = $response->json('messages.0.id');
        $providerError = $response->json('error');
        $error = is_array($providerError) ? $this->errors->providerRejection($response->status(), $providerError) : null;

        return new WhatsAppSendResult($this->name(), $response->successful() ? 'sent' : 'failed', is_string($id) ? $id : null, $error['message'] ?? null, $error['code'] ?? null, ['http_status' => $response->status(), 'target_message_id_present' => true, ...($error['safe_details'] ?? [])]);
    }

    public function markMessageAsRead(string $messageId, ?string $phoneNumberId = null): WhatsAppSendResult
    {
        if (! $this->isConfigured() || $messageId === '') {
            $error = $this->errors->configurationMissing();

            return new WhatsAppSendResult(
                provider: $this->name(),
                status: 'failed',
                errorMessage: $error['message'],
                errorCode: $error['code'],
                safePayload: [
                    'configured' => $this->isConfigured(),
                    'message_id_present' => $messageId !== '',
                    'error_code' => $error['code'],
                ],
            );
        }

        $resolvedPhoneNumberId = $phoneNumberId ?: $this->phoneNumberId();
        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$resolvedPhoneNumberId.'/messages';

        try {
            $response = $this->request()
                ->asJson()
                ->timeout(12)
                ->retry(1, 250, throw: false)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $messageId,
                ]);
        } catch (Throwable $exception) {
            $error = $this->errors->networkFailure($exception);

            return new WhatsAppSendResult(
                provider: $this->name(),
                status: 'failed',
                errorMessage: $error['message'],
                errorCode: $error['code'],
                safePayload: [
                    'http_status' => null,
                    'external_api_called' => true,
                    'message_id_present' => true,
                    'phone_number_id_present' => $resolvedPhoneNumberId !== '',
                    'error_code' => $error['code'],
                    ...$error['safe_details'],
                ],
            );
        }

        $json = $response->json();
        $providerError = is_array($json) && is_array($json['error'] ?? null)
            ? $this->errors->providerRejection($response->status(), $json['error'])
            : $this->errors->providerRejection($response->status(), []);

        return new WhatsAppSendResult(
            provider: $this->name(),
            status: $response->successful() ? 'sent' : 'failed',
            errorMessage: $response->successful() ? null : $providerError['message'],
            errorCode: $response->successful() ? null : $providerError['code'],
            safePayload: [
                'http_status' => $response->status(),
                'external_api_called' => true,
                'message_id_present' => true,
                'phone_number_id_present' => $resolvedPhoneNumberId !== '',
                'error_code' => $response->successful() ? null : $providerError['code'],
                ...($response->successful() ? [] : $providerError['safe_details']),
            ],
        );
    }

    public function downloadMedia(string $mediaId): ?WhatsAppDownloadedMedia
    {
        if (! $this->isConfigured() || $mediaId === '') {
            return null;
        }

        $metadataUrl = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$mediaId;
        $metadataResponse = $this->request()
            ->get($metadataUrl);

        if (! $metadataResponse->successful()) {
            return null;
        }

        $metadata = $metadataResponse->json();
        $downloadUrl = is_array($metadata) ? ($metadata['url'] ?? null) : null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            return null;
        }

        $mediaResponse = $this->request()
            ->get($downloadUrl);

        if (! $mediaResponse->successful()) {
            return null;
        }

        $contents = $mediaResponse->body();

        return new WhatsAppDownloadedMedia(
            contents: $contents,
            mimeType: is_array($metadata) ? ($metadata['mime_type'] ?? $mediaResponse->header('Content-Type')) : $mediaResponse->header('Content-Type'),
            filename: $mediaId,
            sizeBytes: strlen($contents),
            sha256: hash('sha256', $contents),
            safePayload: [
                'http_status' => $mediaResponse->status(),
                'metadata_status' => $metadataResponse->status(),
                'media_id_present' => true,
                'url_received' => true,
                'token_exposed' => false,
            ],
        );
    }

    private function token(): string
    {
        return (string) config('chatbotcrm.whatsapp.meta.token', '');
    }

    private function phoneNumberId(): string
    {
        return (string) config('chatbotcrm.whatsapp.meta.phone_number_id', '');
    }

    private function businessAccountId(): string
    {
        return (string) config('chatbotcrm.whatsapp.meta.business_account_id', '');
    }

    private function verifyToken(): string
    {
        return (string) config('chatbotcrm.whatsapp.meta.verify_token', '');
    }

    private function apiVersion(): string
    {
        return (string) (config('chatbotcrm.whatsapp.meta.api_version') ?: 'v20.0');
    }

    private function graphUrl(): string
    {
        return (string) (config('chatbotcrm.whatsapp.meta.graph_url') ?: 'https://graph.facebook.com');
    }

    private function caBundle(): string
    {
        return trim((string) config('chatbotcrm.whatsapp.meta.ca_bundle', ''));
    }

    private function caBundleIsReadable(): bool
    {
        $bundle = $this->caBundle();

        return $bundle !== '' && is_file($bundle) && is_readable($bundle);
    }

    private function request(): PendingRequest
    {
        $request = Http::withToken($this->token())->acceptJson();

        if ($this->caBundleIsReadable()) {
            $request = $request->withOptions(['verify' => $this->caBundle()]);
        }

        return $request;
    }
}
