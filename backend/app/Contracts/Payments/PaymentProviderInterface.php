<?php

namespace App\Contracts\Payments;

interface PaymentProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function supports(string $method): bool;
}
