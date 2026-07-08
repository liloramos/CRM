<?php

namespace App\Data\WhatsApp;

class OutgoingWhatsAppMessage
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $phoneNumberId = null,
        public readonly array $metadata = [],
    ) {}
}
