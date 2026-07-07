<?php

namespace App\Data\WhatsApp;

use Carbon\CarbonInterface;

class IncomingWhatsAppMessage
{
    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $safeMetadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $providerAccountId,
        public readonly ?string $providerMessageId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $senderName,
        public readonly string $messageType,
        public readonly ?string $text,
        public readonly ?CarbonInterface $sentAt,
        public readonly array $rawPayload = [],
        public readonly array $safeMetadata = [],
    ) {}
}
