<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class WhatsAppPayloadSanitizer
{
    private const SENSITIVE_KEYS = [
        'access_token',
        'app_secret',
        'authorization',
        'password',
        'secret',
        'token',
        'verify_token',
        'x-hub-signature',
        'x-hub-signature-256',
    ];

    /**
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->sanitizeValue($payload);
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $sanitized;
        }

        if (is_string($value) && $key !== null && in_array(strtolower($key), ['body', 'text', 'caption'], true)) {
            return Str::limit($value, 120);
        }

        return $value;
    }
}
