<?php

namespace App\Data\Ai;

class AutomationDispatchResult
{
    /**
     * @param  array<string, mixed>  $safePayload
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $errorMessage = null,
        public readonly array $safePayload = [],
    ) {}

    public function successful(): bool
    {
        return in_array($this->status, ['dispatched', 'accepted', 'queued'], true);
    }

    public function skipped(): bool
    {
        return $this->status === 'skipped';
    }
}
