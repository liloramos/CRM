<?php

namespace App\Champs\DTOs;

final readonly class ResolvedInstagramProfile
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /**
     * @param  list<string>  $candidates
     */
    public function __construct(
        public string $username,
        public string $profileUrl,
        public string $confidence,
        public string $sourceUrl,
        public bool $requiresReview = false,
        public array $candidates = [],
    ) {}
}
