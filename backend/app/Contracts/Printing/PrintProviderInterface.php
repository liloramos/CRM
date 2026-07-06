<?php

namespace App\Contracts\Printing;

interface PrintProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;
}
