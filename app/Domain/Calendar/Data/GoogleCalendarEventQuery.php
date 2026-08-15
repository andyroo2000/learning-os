<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarEventQuery
{
    public function __construct(public ?string $timeMin = null, public ?string $timeMax = null, public ?string $syncToken = null, public ?string $pageToken = null, public int $maxResults = 250) {}
}
