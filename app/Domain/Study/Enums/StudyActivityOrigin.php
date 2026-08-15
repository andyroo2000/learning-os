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
}
