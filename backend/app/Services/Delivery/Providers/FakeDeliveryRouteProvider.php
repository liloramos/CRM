<?php

namespace App\Services\Delivery\Providers;

use App\Contracts\Delivery\DeliveryRouteProviderInterface;
use App\Data\Delivery\DeliveryCoordinates;
use App\Data\Delivery\DeliveryRoute;
use DomainException;

class FakeDeliveryRouteProvider implements DeliveryRouteProviderInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function calculateRoute(DeliveryCoordinates $origin, DeliveryCoordinates $destination): DeliveryRoute
    {
        throw new DomainException('O cálculo de rota não está configurado para este ambiente.');
    }
}
