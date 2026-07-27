<?php

namespace App\Data\WhatsApp;

class WhatsAppDownloadedMedia
{
    /**
     * @param  array<string, mixed>  $safePayload
     */
    public function __construct(
        public readonly string $contents,
        public readonly ?string $mimeType = null,
        public readonly ?string $filename = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $sha256 = null,
        public readonly array $safePayload = [],
    ) {}
}
