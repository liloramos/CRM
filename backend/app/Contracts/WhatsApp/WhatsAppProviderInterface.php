<?php

namespace App\Contracts\WhatsApp;

interface WhatsAppProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;
}
