<?php

namespace App\Domain\Auth\Data;

final readonly class ConvoLabGoogleProfile
{
    public function __construct(
        public string $providerId,
        public string $email,
        public string $name,
        public ?string $avatarUrl,
        public bool $emailVerified,
    ) {}
}
