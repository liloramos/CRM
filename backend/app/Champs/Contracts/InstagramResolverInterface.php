<?php

namespace App\Champs\Contracts;

use App\Champs\DTOs\ResolvedInstagramProfile;

interface InstagramResolverInterface
{
    public function resolve(string $websiteUrl): ?ResolvedInstagramProfile;
}
