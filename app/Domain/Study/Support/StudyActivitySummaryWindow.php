<?php

namespace App\Domain\Study\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

final readonly class StudyActivitySummaryWindow
{
    public const MAX_DAYS = 3660;

    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    public function __construct(
        public DateTimeZone $timezone,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        public CarbonImmutable $now,
    ) {
        $this->startsAt = $startsAt->setTimezone($timezone)->startOfDay();
        $this->endsAt = $endsAt->setTimezone($timezone)->startOfDay()
            ->min($now->setTimezone($timezone)->startOfDay());
    }

    /**
     * A null result requests the exact raw-session fallback, before expanding
     * exceptionally long imported histories into thousands of daily rows.
     *
     * @return list<array{date: string, start: CarbonImmutable, end: CarbonImmutable, startUs: int, endUs: int}>|null
     */
    public function days(): ?array
    {
        if ($this->startsAt->diffInDays($this->endsAt, false) > self::MAX_DAYS) {
            return null;
        }
        $days = [];
        for ($date = $this->startsAt; $date->lessThan($this->endsAt); $date = $next) {
            // Calendar arithmetic intentionally follows 23/25-hour local days.
            $next = $date->addDay();
            $days[] = [
                'date' => $date->toDateString(),
                'start' => $date->utc(),
                'end' => $next->utc(),
                'startUs' => StudyActivityIntervalAllocation::microseconds($date),
                'endUs' => StudyActivityIntervalAllocation::microseconds($next),
            ];
        }

        return $days;
    }
}
