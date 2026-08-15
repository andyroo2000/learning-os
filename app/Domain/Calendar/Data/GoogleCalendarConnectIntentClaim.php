<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarConnectIntentClaim
{
    public function __construct(public int $userId, public string $completionTarget) {}
}
