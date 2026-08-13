<?php

namespace App\Data\WhatsApp;

class WhatsAppMediaUploadResult
{
    public function __construct(
        public readonly string $mediaId,
        public readonly array $safePayload = [],
    ) {}
}
