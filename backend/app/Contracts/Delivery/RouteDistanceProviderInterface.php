<?php

namespace App\Contracts\Delivery;

use App\Models\CustomerAddress;

interface RouteDistanceProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function estimateDistanceKm(CustomerAddress $address): ?float;
}
