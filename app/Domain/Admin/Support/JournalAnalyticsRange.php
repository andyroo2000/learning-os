<?php

namespace App\Domain\Admin\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class JournalAnalyticsRange
{
    public CarbonImmutable $start;

    public function __construct(public string $period, public CarbonImmutable $end)
    {
        $this->start = match ($period) {
            'daily' => $end->startOfDay()->subDays(27),
            'weekly' => $end->startOfWeek()->subWeeks(11),
            'monthly' => $end->startOfMonth()->subMonths(11),
            'yearly' => $end->startOfYear()->subYears(4),
            default => throw new InvalidArgumentException('Invalid analytics period'),
        };
    }

    public function key(CarbonImmutable $time): string
    {
        return match ($this->period) {
            'daily' => $time->toDateString(),
            'weekly' => $time->startOfWeek()->toDateString(),
            'monthly' => $time->startOfMonth()->toDateString(),
            'yearly' => $time->startOfYear()->toDateString(),
        };
    }

    public function emptyBuckets(): array
    {
        $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$this->period];
        $buckets = [];
        for ($date = $this->start; $date->lte($this->end); $date = $date->addUnit($unit)) {
            $buckets[$date->toDateString()] = new JournalWritingCounts;
        }

        return $buckets;
    }
}
