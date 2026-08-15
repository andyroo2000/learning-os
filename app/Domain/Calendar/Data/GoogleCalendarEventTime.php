<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarEventTime
{
    public function __construct(public ?string $date, public ?string $dateTime, public ?string $timeZone) {}
}
