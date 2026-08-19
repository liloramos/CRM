<?php

namespace App\Services\Ai\Providers;

use RuntimeException;

final class CopilotProviderFailure extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(private readonly array $details)
    {
        parent::__construct((string) ($details['message'] ?? 'The copilot provider request failed.'));
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
