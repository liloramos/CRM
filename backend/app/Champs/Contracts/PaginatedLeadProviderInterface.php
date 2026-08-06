<?php

namespace App\Champs\Contracts;

use App\Champs\DTOs\LeadDiscoveryPage;
use App\Champs\DTOs\LeadDiscoveryRequest;

interface PaginatedLeadProviderInterface
{
    public function discoverPage(
        LeadDiscoveryRequest $request,
        ?string $pageToken = null,
    ): LeadDiscoveryPage;
}
