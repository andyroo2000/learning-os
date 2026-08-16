<?php

namespace App\Domain\Study\Support;

use App\Domain\Calendar\Data\GoogleCalendarEvent;
use InvalidArgumentException;

final class GoogleCalendarEventIdentity
{
    public static function sourceKey(GoogleCalendarEvent $event, string $accountId, string $calendarId): ?StudyActivitySourceKey
    {
        try {
            if ($event->recurringEventId !== null) {
                $instance = $event->originalStartTime?->dateTime ?? $event->originalStartTime?->date;

                return $instance === null ? null : StudyActivitySourceKey::forGoogleCalendar($accountId, $calendarId, $event->recurringEventId, $instance);
            }

            return StudyActivitySourceKey::forGoogleCalendar($accountId, $calendarId, $event->id);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
