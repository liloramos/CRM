<?php

namespace App\Data\Ai;

class AiProviderStatus
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $provider,
        public readonly bool $configured,
        public readonly string $status,
        public readonly array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'configured' => $this->configured,
            'status' => $this->status,
            'details' => $this->details,
        ];
    }
}
