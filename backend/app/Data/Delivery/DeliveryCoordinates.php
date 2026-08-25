<?php

namespace App\Data\Delivery;

use DomainException;

final readonly class DeliveryCoordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new DomainException('Coordenadas de localização inválidas.');
        }
    }

    /** @return array{latitude: float, longitude: float} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
