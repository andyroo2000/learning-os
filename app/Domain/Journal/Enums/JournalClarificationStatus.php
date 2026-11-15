<?php

namespace App\Domain\Journal\Enums;

enum JournalClarificationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Failed = 'failed';
}
