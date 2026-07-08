<?php

namespace App\Services\WhatsApp\Providers;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Data\WhatsApp\WhatsAppConnectionStatus;
use App\Data\WhatsApp\WhatsAppSendResult;
use App\Services\WhatsApp\MetaWebhookPayloadParser;
use Illuminate\Support\Facades\Http;

class MetaCloudWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(private readonly MetaWebhookPayloadParser $parser) {}

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
            ],
        );
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
            return new WhatsAppSendResult(
                provider: $this->name(),
                status: 'failed',
                errorMessage: 'Meta WhatsApp provider is missing configuration.',
                safePayload: ['configured' => false],
            );
        }

        $phoneNumberId = $message->phoneNumberId ?: $this->phoneNumberId();
        $url = rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$phoneNumberId.'/messages';

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $message->to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message->body,
                ],
            ]);

        $json = $response->json();
        $providerMessageId = is_array($json) ? ($json['messages'][0]['id'] ?? null) : null;

        return new WhatsAppSendResult(
            provider: $this->name(),
            status: $response->successful() ? 'sent' : 'failed',
            providerMessageId: $providerMessageId,
            errorMessage: $response->successful() ? null : 'Meta WhatsApp request failed with status '.$response->status().'.',
            safePayload: [
                'http_status' => $response->status(),
                'recipient_present' => $message->to !== '',
                'body_length' => strlen($message->body),
                'provider_message_id_present' => $providerMessageId !== null,
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
}
