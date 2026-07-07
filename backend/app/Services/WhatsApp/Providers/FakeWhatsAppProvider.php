<?php

namespace App\Services\WhatsApp\Providers;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Data\WhatsApp\WhatsAppConnectionStatus;
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
            ],
        );
    }
}
