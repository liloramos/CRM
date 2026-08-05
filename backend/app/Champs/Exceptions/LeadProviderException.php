<?php

namespace App\Champs\Exceptions;

use RuntimeException;

final class LeadProviderException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Google Places provider is disabled.');
    }

    public static function missingApiKey(): self
    {
        return new self('Google Places provider is missing its API key.');
    }

    public static function invalidConfiguration(): self
    {
        return new self('Google Places provider configuration is invalid.');
    }

    public static function invalidResponse(): self
    {
        return new self('Google Places returned an invalid response.');
    }

    public static function credentialsRejected(int $status): self
    {
        return new self("Google Places rejected the configured credentials (HTTP {$status}).");
    }

    public static function rateLimited(): self
    {
        return new self('Google Places rate limit was reached. Try again later.');
    }

    public static function serverFailure(int $status): self
    {
        return new self("Google Places is temporarily unavailable (HTTP {$status}).");
    }

    public static function requestFailure(int $status): self
    {
        return new self("Google Places request failed (HTTP {$status}).");
    }

    public static function connectionFailure(): self
    {
        return new self('Google Places request timed out or could not connect.');
    }
}
