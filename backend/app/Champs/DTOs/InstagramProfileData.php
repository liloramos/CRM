<?php

namespace App\Champs\DTOs;

final readonly class InstagramProfileData
{
    public function __construct(
        public bool $enrichable,
        public string $username,
        public ?string $id = null,
        public ?string $name = null,
        public ?string $biography = null,
        public ?string $website = null,
        public ?int $followersCount = null,
        public ?int $mediaCount = null,
        public ?string $profilePictureUrl = null,
        public bool $isProfessional = false,
        public ?string $reason = null,
    ) {}

    public static function notEnrichable(string $username): self
    {
        return new self(
            enrichable: false,
            username: $username,
            reason: 'A conta nao esta disponivel para Business Discovery.',
        );
    }
}
