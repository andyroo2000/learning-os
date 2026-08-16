<?php

namespace App\Domain\Calendar\Enums;

enum GoogleCalendarSyncStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
