<?php

namespace App\Services\WhatsApp;

class WhatsAppAudioFormat
{
    /** @var list<string> */
    public const BROWSER_RECORDABLE_TYPES = [
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm;codecs=opus',
        'audio/webm',
    ];

    /** @var list<string> */
    public const WHATSAPP_OUTBOUND_AUDIO_TYPES = [
        'audio/aac',
        'audio/amr',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
    ];

    public static function canonicalize(?string $mimeType): string
    {
        return strtolower(trim(explode(';', (string) $mimeType, 2)[0]));
    }

    public static function isBrowserRecordable(?string $mimeType): bool
    {
        return in_array(strtolower(trim((string) $mimeType)), self::BROWSER_RECORDABLE_TYPES, true);
    }

    public static function isWhatsAppOutboundType(?string $mimeType): bool
    {
        return in_array(self::canonicalize($mimeType), self::WHATSAPP_OUTBOUND_AUDIO_TYPES, true);
    }
}
