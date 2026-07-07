<?php

namespace App\Contracts\WhatsApp;

use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Data\WhatsApp\OutgoingWhatsAppMessage;
use App\Data\WhatsApp\WhatsAppConnectionStatus;
use App\Data\WhatsApp\WhatsAppSendResult;

interface WhatsAppProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function connectionStatus(): WhatsAppConnectionStatus;

    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string;

    /**
     * @return list<IncomingWhatsAppMessage>
     */
    public function parseWebhookPayload(array $payload): array;

    public function sendTextMessage(OutgoingWhatsAppMessage $message): WhatsAppSendResult;
}
