<?php

namespace App\Data\WhatsApp;

class NormalizedWhatsAppAudio
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly string $action,
        public readonly array $metadata = [],
    ) {}
}
