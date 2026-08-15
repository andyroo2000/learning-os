<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarOAuthGrant
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $providerAccountId,
        public string $email,
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresIn,
        public array $scopes,
    ) {}
}
