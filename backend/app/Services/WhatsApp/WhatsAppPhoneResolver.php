<?php

namespace App\Services\WhatsApp;

class WhatsAppPhoneResolver
{
    /** @return array{provider_identity: string, canonical_phone: string|null, normalization_applied: bool, confidence: 'high'|'none', source: string} */
    public function resolve(string $providerIdentity, ?string $existingPhone = null): array
    {
        $provider = $this->digits($providerIdentity);
        $existing = $this->digits($existingPhone);

        if ($existing !== '') {
            if ($this->isCanonicalBrazilianMobile($existing) && $this->matchesBrazilianIdentity($provider, $existing)) {
                return $this->result($provider, $existing, false, 'existing_canonical_phone');
            }

            if ($existing === $provider) {
                return $this->resolveProviderIdentity($provider, 'existing_phone');
            }

            return $this->result($provider, $existing, false, 'existing_phone');
        }

        return $this->resolveProviderIdentity($provider, 'provider_identity');
    }

    private function resolveProviderIdentity(string $provider, string $fallbackSource): array
    {
        if ($this->isCanonicalBrazilianMobile($provider)) {
            return $this->result($provider, $provider, false, 'provider_canonical_mobile');
        }

        if (preg_match('/^55\d{2}[6-9]\d{7}$/', $provider) === 1) {
            return $this->result($provider, substr($provider, 0, 4).'9'.substr($provider, 4), true, 'brazilian_mobile_inferred');
        }

        if (preg_match('/^55\d{2}[2-5]\d{7}$/', $provider) === 1) {
            return $this->result($provider, $provider, false, 'brazilian_fixed_line');
        }

        return $this->result($provider, null, false, $fallbackSource);
    }

    private function isCanonicalBrazilianMobile(string $value): bool
    {
        return preg_match('/^55\d{2}9\d{8}$/', $value) === 1;
    }

    private function matchesBrazilianIdentity(string $provider, string $canonicalPhone): bool
    {
        return $this->isCanonicalBrazilianMobile($canonicalPhone)
            && substr($canonicalPhone, 0, 4).substr($canonicalPhone, 5) === $provider;
    }

    /** @return array{provider_identity: string, canonical_phone: string|null, normalization_applied: bool, confidence: 'high'|'none', source: string} */
    private function result(string $provider, ?string $canonical, bool $applied, string $source): array
    {
        return [
            'provider_identity' => $provider,
            'canonical_phone' => $canonical,
            'normalization_applied' => $applied,
            'confidence' => $canonical === null ? 'none' : 'high',
            'source' => $source,
        ];
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }
}
