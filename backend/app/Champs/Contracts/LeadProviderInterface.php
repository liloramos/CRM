<?php

namespace App\Champs\Contracts;

use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\LeadDiscoveryRequest;

interface LeadProviderInterface
{
    public function name(): string;

    /**
     * @return list<DiscoveredLead>
     */
    public function discover(LeadDiscoveryRequest $request): array;
}
