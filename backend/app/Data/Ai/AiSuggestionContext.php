<?php

namespace App\Data\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class AiSuggestionContext
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Company $company,
        public readonly Conversation $conversation,
        public readonly ?Message $message = null,
        public readonly ?User $requestedBy = null,
        public readonly string $automationMode = 'assisted',
        public readonly array $settings = [],
        public readonly array $metadata = [],
    ) {}

    public function latestContent(): string
    {
        return trim((string) ($this->message?->content ?? ''));
    }
}
