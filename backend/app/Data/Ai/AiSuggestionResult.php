<?php

namespace App\Data\Ai;

class AiSuggestionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $suggestedText,
        public readonly string $suggestionType = 'reply',
        public readonly ?float $confidenceScore = null,
        public readonly bool $requiresHumanConfirmation = true,
        public readonly ?string $ambiguityReason = null,
        public readonly ?string $safetyNotes = null,
        public readonly array $metadata = [],
    ) {}
}
