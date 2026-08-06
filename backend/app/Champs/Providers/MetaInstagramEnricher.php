<?php

namespace App\Champs\Providers;

use App\Champs\Contracts\InstagramEnricherInterface;
use App\Champs\DTOs\InstagramProfileData;
use App\Champs\Exceptions\InstagramEnrichmentException;
use App\Champs\Support\InstagramUsername;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class MetaInstagramEnricher implements InstagramEnricherInterface
{
    public const BUSINESS_DISCOVERY_FIELDS = [
        'id',
        'username',
        'name',
        'biography',
        'website',
        'followers_count',
        'media_count',
        'profile_picture_url',
    ];

    public function isAvailable(): bool
    {
        return (bool) config('champs.meta.enabled', false)
            && $this->accessToken() !== ''
            && $this->instagramAccountId() !== ''
            && $this->hasValidInstagramAccountId()
            && $this->hasValidApiVersion()
            && $this->hasValidBaseUrl()
            && $this->timeout() > 0;
    }

    public function enrich(string $username): InstagramProfileData
    {
        $normalizedUsername = InstagramUsername::normalize($username);

        if ($normalizedUsername === null) {
            throw InstagramEnrichmentException::invalidUsername();
        }

        $this->ensureConfigured();

        try {
            $response = Http::acceptJson()
                ->withToken($this->accessToken())
                ->connectTimeout(min(5, $this->timeout()))
                ->timeout($this->timeout())
                ->get($this->endpoint(), [
                    'fields' => $this->businessDiscoveryField($normalizedUsername),
                ]);
        } catch (ConnectionException) {
            throw InstagramEnrichmentException::connectionFailure();
        }

        $nonEnrichable = $this->handleResponseStatus($response, $normalizedUsername);

        if ($nonEnrichable !== null) {
            return $nonEnrichable;
        }

        $payload = $this->decode($response);
        $profile = $payload['business_discovery'] ?? null;

        if ($profile === null) {
            return InstagramProfileData::notEnrichable($normalizedUsername);
        }

        if (! is_array($profile)) {
            throw InstagramEnrichmentException::invalidResponse();
        }

        return new InstagramProfileData(
            enrichable: true,
            username: InstagramUsername::normalize((string) ($profile['username'] ?? ''))
                ?? $normalizedUsername,
            id: $this->nullableString($profile['id'] ?? null),
            name: $this->nullableString($profile['name'] ?? null),
            biography: $this->nullableString($profile['biography'] ?? null),
            website: $this->nullableString($profile['website'] ?? null),
            followersCount: $this->nullableNonNegativeInteger($profile['followers_count'] ?? null),
            mediaCount: $this->nullableNonNegativeInteger($profile['media_count'] ?? null),
            profilePictureUrl: $this->nullableString($profile['profile_picture_url'] ?? null),
            isProfessional: true,
        );
    }

    private function ensureConfigured(): void
    {
        if (! (bool) config('champs.meta.enabled', false)) {
            throw InstagramEnrichmentException::disabled();
        }

        if ($this->accessToken() === ''
            || $this->instagramAccountId() === ''
            || ! $this->hasValidInstagramAccountId()
            || ! $this->hasValidApiVersion()
            || ! $this->hasValidBaseUrl()
            || $this->timeout() < 1) {
            throw InstagramEnrichmentException::missingConfiguration();
        }
    }

    private function handleResponseStatus(
        Response $response,
        string $username,
    ): ?InstagramProfileData {
        $status = $response->status();
        $errorCode = $this->errorCode($response);

        if ($status === 401 || $errorCode === 190) {
            throw InstagramEnrichmentException::credentialsRejected();
        }

        if ($status === 403) {
            throw InstagramEnrichmentException::forbidden();
        }

        if ($status === 429 || in_array($errorCode, [4, 17, 32, 613], true)) {
            throw InstagramEnrichmentException::rateLimited();
        }

        if ($response->serverError()) {
            throw InstagramEnrichmentException::serverFailure();
        }

        if (in_array($status, [400, 404], true)) {
            return InstagramProfileData::notEnrichable($username);
        }

        if ($response->failed()) {
            throw InstagramEnrichmentException::requestFailed();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InstagramEnrichmentException::invalidResponse();
        }

        if (! is_array($payload)) {
            throw InstagramEnrichmentException::invalidResponse();
        }

        return $payload;
    }

    private function errorCode(Response $response): ?int
    {
        try {
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $code = is_array($payload) ? data_get($payload, 'error.code') : null;

        return is_numeric($code) ? (int) $code : null;
    }

    private function businessDiscoveryField(string $username): string
    {
        return 'business_discovery.username('.$username.')'
            .'{'.implode(',', self::BUSINESS_DISCOVERY_FIELDS).'}';
    }

    private function endpoint(): string
    {
        return $this->baseUrl().'/'.$this->apiVersion().'/'.$this->instagramAccountId();
    }

    private function hasValidBaseUrl(): bool
    {
        $parts = parse_url($this->baseUrl());

        return ($parts['scheme'] ?? null) === 'https'
            && mb_strtolower((string) ($parts['host'] ?? '')) === 'graph.facebook.com'
            && in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private function hasValidApiVersion(): bool
    {
        return preg_match('/^v\d+\.\d+$/', $this->apiVersion()) === 1;
    }

    private function hasValidInstagramAccountId(): bool
    {
        return preg_match('/^\d+$/', $this->instagramAccountId()) === 1;
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('champs.meta.graph_base_url', '')), '/');
    }

    private function apiVersion(): string
    {
        return trim((string) config('champs.meta.api_version', ''), " /\t\n\r\0\x0B");
    }

    private function accessToken(): string
    {
        return trim((string) config('champs.meta.access_token', ''));
    }

    private function instagramAccountId(): string
    {
        return trim((string) config('champs.meta.instagram_account_id', ''));
    }

    private function timeout(): int
    {
        return (int) config('champs.meta.timeout', 15);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableNonNegativeInteger(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
