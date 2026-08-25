<?php

namespace App\Contracts\Delivery;

use App\Data\Delivery\DeliveryCoordinates;
use App\Data\Delivery\DeliveryRoute;

interface DeliveryRouteProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function calculateRoute(DeliveryCoordinates $origin, DeliveryCoordinates $destination): DeliveryRoute;
}
