<?php

namespace App\Domain\Calendar\Data;

final readonly class GoogleCalendar
{
    public function __construct(public string $id, public string $summary, public bool $primary, public string $accessRole) {}
}
