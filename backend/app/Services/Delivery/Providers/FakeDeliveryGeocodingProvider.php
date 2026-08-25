<?php

namespace App\Services\Delivery\Providers;

use App\Contracts\Delivery\DeliveryGeocodingProviderInterface;
use App\Data\Delivery\GeocodedDeliveryAddress;
use DomainException;

class FakeDeliveryGeocodingProvider implements DeliveryGeocodingProviderInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function geocode(string $address): GeocodedDeliveryAddress
    {
        if (trim($address) === '') {
            throw new DomainException('Informe um endereço para calcular a rota.');
        }

        throw new DomainException('O geocoding não está configurado para este ambiente.');
    }
}
