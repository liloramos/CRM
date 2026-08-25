<?php

namespace App\Contracts\Delivery;

use App\Data\Delivery\GeocodedDeliveryAddress;

interface DeliveryGeocodingProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function geocode(string $address): GeocodedDeliveryAddress;
}
