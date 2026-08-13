<?php

namespace App\Services\WhatsApp\Providers;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Data\WhatsApp\WhatsAppConnectionStatus;
use App\Data\WhatsApp\WhatsAppDownloadedMedia;
use App\Data\WhatsApp\WhatsAppMediaUploadResult;
use App\Data\WhatsApp\WhatsAppSendResult;
use App\Services\WhatsApp\MetaWebhookPayloadParser;
use Illuminate\Support\Str;

class FakeWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(private readonly MetaWebhookPayloadParser $parser) {}

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function connectionStatus(): WhatsAppConnectionStatus
    {
        return new WhatsAppConnectionStatus(
            provider: $this->name(),
            configured: true,
            status: 'connected',
            details: [
                'transport' => 'local_fake',
                'external_api_called' => false,
            ],
        );
    }

    public function diagnoseConnectivity(): array
    {
        return [
            'status' => 'available',
            'http_status' => null,
            'error_code' => null,
            'external_api_called' => false,
        ];
    }

    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expectedToken = config('chatbotcrm.whatsapp.fake.verify_token')
            ?: config('chatbotcrm.whatsapp.meta.verify_token');

        if ($mode !== 'subscribe' || $challenge === null) {
            return null;
        }

        if ($expectedToken === null || $expectedToken === '') {
            return app()->isProduction() ? null : $challenge;
        }

        return hash_equals((string) $expectedToken, (string) $token) ? $challenge : null;
    }

    public function parseWebhookPayload(array $payload): array
    {
        return $this->parser->parse($payload, $this->name());
    }

    public function sendTextMessage(OutgoingWhatsAppMessage $message): WhatsAppSendResult
    {
        return new WhatsAppSendResult(
            provider: $this->name(),
            status: 'sent',
            providerMessageId: 'fake_'.Str::uuid()->toString(),
            safePayload: [
                'transport' => 'local_fake',
                'recipient_present' => $message->to !== '',
                'body_length' => strlen($message->body),
                'reply_context_present' => $message->replyToProviderMessageId !== null,
            ],
        );
    }

    public function uploadMedia(string $contents, string $mimeType, string $filename, ?string $phoneNumberId = null): ?WhatsAppMediaUploadResult
    {
        return new WhatsAppMediaUploadResult('fake_media_'.Str::uuid()->toString(), [
            'transport' => 'local_fake',
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
        ]);
    }

    public function sendMediaMessage(OutgoingWhatsAppMessage $message, string $mediaId, string $mediaType, ?string $filename = null): WhatsAppSendResult
    {
        return new WhatsAppSendResult($this->name(), 'sent', 'fake_'.Str::uuid()->toString(), null, null, [
            'transport' => 'local_fake',
            'media_id_present' => $mediaId !== '',
            'message_type' => $mediaType,
        ]);
    }

    public function markMessageAsRead(string $messageId, ?string $phoneNumberId = null): WhatsAppSendResult
    {
        return new WhatsAppSendResult(
            provider: $this->name(),
            status: 'sent',
            safePayload: [
                'transport' => 'local_fake',
                'external_api_called' => false,
                'message_id_present' => $messageId !== '',
                'phone_number_id_present' => ($phoneNumberId ?? '') !== '',
            ],
        );
    }

    public function downloadMedia(string $mediaId): ?WhatsAppDownloadedMedia
    {
        if ($mediaId === '') {
            return null;
        }

        $contents = "Arquivo ficticio de desenvolvimento para {$mediaId}.\n";

        return new WhatsAppDownloadedMedia(
            contents: $contents,
            mimeType: 'text/plain',
            filename: $mediaId.'.txt',
            sizeBytes: strlen($contents),
            sha256: hash('sha256', $contents),
            safePayload: [
                'transport' => 'local_fake',
                'external_api_called' => false,
                'media_id_present' => true,
            ],
        );
    }
}
