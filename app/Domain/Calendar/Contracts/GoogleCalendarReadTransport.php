<?php

namespace App\Domain\Calendar\Contracts;

use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;

interface GoogleCalendarReadTransport
{
    public function refresh(string $refreshToken): GoogleCalendarTokenGrant;

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage;

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage;
}
