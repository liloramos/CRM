<?php

namespace App\Contracts\Ai;

interface AiProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;
}
