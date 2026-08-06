<?php

namespace App\Champs\DTOs;

use InvalidArgumentException;

final readonly class LeadDiscoveryPage
{
    /**
     * @param  list<DiscoveredLead>  $leads
     */
    public function __construct(
        public array $leads,
        public ?string $nextPageToken = null,
    ) {
        foreach ($leads as $lead) {
            if (! $lead instanceof DiscoveredLead) {
                throw new InvalidArgumentException('A discovery page must contain discovered leads only.');
            }
        }

        if ($nextPageToken !== null && trim($nextPageToken) === '') {
            throw new InvalidArgumentException('A discovery page token cannot be empty.');
        }
    }
}
