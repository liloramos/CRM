<?php

namespace App\Data\Delivery;

final readonly class GeocodedDeliveryAddress
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $formattedAddress,
        public DeliveryCoordinates $coordinates,
        public string $provider,
        public array $metadata = [],
    ) {}
}
