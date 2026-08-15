<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarTokenGrant
{
    public function __construct(public string $accessToken, public int $expiresIn, public ?string $refreshToken) {}
}
