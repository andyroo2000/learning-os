<?php

namespace App\Domain\Journal\Enums;

enum JournalAnalysisStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
