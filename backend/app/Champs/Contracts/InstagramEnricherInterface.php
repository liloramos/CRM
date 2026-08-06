<?php

namespace App\Champs\Contracts;

use App\Champs\DTOs\InstagramProfileData;

interface InstagramEnricherInterface
{
    public function isAvailable(): bool;

    public function enrich(string $username): InstagramProfileData;
}
