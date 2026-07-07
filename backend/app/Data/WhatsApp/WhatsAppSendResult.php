<?php

namespace App\Data\WhatsApp;

class WhatsAppSendResult
{
    /**
     * @param  array<string, mixed>  $safePayload
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorMessage = null,
        public readonly array $safePayload = [],
    ) {}

    public function successful(): bool
    {
        return in_array($this->status, ['sent', 'accepted', 'queued'], true);
    }
}
