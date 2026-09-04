<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Support\StudyActivitySourceKey;
use Carbon\CarbonImmutable;

final readonly class GoogleCalendarStudyEventContext
{
    public function __construct(
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public bool $allDay,
        public ?StudyActivitySourceKey $key,
    ) {}
}
