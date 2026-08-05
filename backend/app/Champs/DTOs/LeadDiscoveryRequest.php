<?php

namespace App\Champs\DTOs;

use InvalidArgumentException;

final readonly class LeadDiscoveryRequest
{
    private const MAX_NICHE_LENGTH = 120;

    private const MAX_CITY_LENGTH = 100;

    private const MAX_STATE_LENGTH = 20;

    public string $niche;

    public string $city;

    public string $state;

    public int $limit;

    public function __construct(string $niche, string $city, string $state, int $limit)
    {
        $this->niche = $this->normalizeRequiredText($niche, 'niche', self::MAX_NICHE_LENGTH);
        $this->city = $this->normalizeRequiredText($city, 'city', self::MAX_CITY_LENGTH);
        $this->state = $this->normalizeRequiredText($state, 'state', self::MAX_STATE_LENGTH);

        $maxResults = (int) config('champs.google_places.max_results', 20);

        if ($maxResults < 1) {
            throw new InvalidArgumentException('Google Places max results configuration must be at least 1.');
        }

        if ($limit < 1 || $limit > $maxResults) {
            throw new InvalidArgumentException("Lead discovery limit must be between 1 and {$maxResults}.");
        }

        $this->limit = $limit;
    }

    public function textQuery(): string
    {
        return "{$this->niche} em {$this->city} {$this->state}";
    }

    private function normalizeRequiredText(string $value, string $field, int $maxLength): string
    {
        $withoutControlCharacters = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        if ($withoutControlCharacters === null) {
            throw new InvalidArgumentException("Lead discovery {$field} contains invalid characters.");
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($withoutControlCharacters));

        if ($normalized === null || $normalized === '') {
            throw new InvalidArgumentException("Lead discovery {$field} is required.");
        }

        if (mb_strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException("Lead discovery {$field} may not exceed {$maxLength} characters.");
        }

        return $normalized;
    }
}
