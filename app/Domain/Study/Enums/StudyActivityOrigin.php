<?php

namespace App\Domain\Study\Enums;

enum StudyActivityOrigin: string
{
    case Legacy = 'legacy';
    case Ios = 'ios';
    case Web = 'web';
    case GoogleCalendar = 'google_calendar';
    case WaniKani = 'wanikani';
    case System = 'system';

    /** @return list<string> */
    public static function clientValues(): array
    {
        return [self::Ios->value, self::Web->value];
    }
}
