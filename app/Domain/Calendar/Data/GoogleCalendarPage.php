<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendarPage
{
    public function __construct(public array $items, public ?string $nextPageToken, public ?string $nextSyncToken) {}
}
