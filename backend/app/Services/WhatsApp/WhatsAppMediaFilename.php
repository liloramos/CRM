<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class WhatsAppMediaFilename
{
    public static function forMedia(?string $originalFilename, ?string $mimeType, string $mediaType, ?string $createdAt = null, int|string|null $id = null): string
    {
        $extension = self::extensionFor($mimeType);
        $candidate = trim((string) $originalFilename);
        $baseName = pathinfo($candidate, PATHINFO_FILENAME);
        $baseName = Str::of($baseName)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9_-]+/', '-')
            ->trim('-')
            ->limit(80, '')
            ->toString();

        if ($baseName === '' || ($mediaType !== 'document' && pathinfo($candidate, PATHINFO_EXTENSION) === '')) {
            $prefix = match ($mediaType) {
                'image' => 'imagem',
                'video' => 'video',
                'audio' => 'audio',
                default => 'arquivo',
            };
            $timestamp = $createdAt ? str_replace([':', '+', 'T'], ['', '', '-'], $createdAt) : now()->format('Ymd-His');
            $baseName = $prefix.'-'.$timestamp.($id !== null ? '-'.$id : '');
        }

        return $baseName.$extension;
    }

    public static function extensionFor(?string $mimeType): string
    {
        return match (strtolower(trim(explode(';', (string) $mimeType, 2)[0]))) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            'audio/ogg' => '.ogg',
            'audio/mpeg' => '.mp3',
            'audio/mp4' => '.m4a',
            'audio/aac' => '.aac',
            'audio/amr' => '.amr',
            'video/mp4' => '.mp4',
            'video/3gpp' => '.3gp',
            'text/plain' => '.txt',
            default => '.bin',
        };
    }
}
