<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarEvent
{
    public function __construct(public string $id, public string $status, public ?string $summary, public ?GoogleCalendarEventTime $start, public ?GoogleCalendarEventTime $end, public ?GoogleCalendarEventTime $originalStartTime, public ?string $updated, public ?string $recurringEventId) {}
}
