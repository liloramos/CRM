<?php

namespace App\Contracts\Integrations;

interface IntegrationProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;
}
