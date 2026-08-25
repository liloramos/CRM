<?php

namespace App\Data\Delivery;

final readonly class DeliveryRoute
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $distanceMeters,
        public ?int $durationSeconds,
        public string $provider,
        public ?string $externalRouteId = null,
        public ?string $encodedPolyline = null,
        public array $metadata = [],
    ) {}
}
