<?php

namespace App\Domain\Calendar\Support;

use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Study\Support\StudyActivitySourceKey;
use InvalidArgumentException;

final class GoogleCalendarEventIdentity
{
    public static function sourceKey(GoogleCalendarEvent $event, string $accountId, string $calendarId): ?StudyActivitySourceKey
    {
        try {
            if ($event->recurringEventId !== null) {
                $instance = $event->originalStartTime?->dateTime;

                return $instance === null ? null : StudyActivitySourceKey::forGoogleCalendar($accountId, $calendarId, $event->recurringEventId, $instance);
            }

            return StudyActivitySourceKey::forGoogleCalendar($accountId, $calendarId, $event->id);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
