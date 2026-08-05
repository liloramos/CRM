<?php

namespace App\Champs\DTOs;

final readonly class DiscoveredLead
{
    /**
     * @param  array<string, mixed>|null  $rawData
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $name,
        public string $formattedAddress,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $website = null,
        public ?float $rating = null,
        public int $userRatingCount = 0,
        public ?string $businessStatus = null,
        public ?array $rawData = null,
    ) {}
}
