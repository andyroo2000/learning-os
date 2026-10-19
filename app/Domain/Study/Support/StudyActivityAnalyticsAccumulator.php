<?php

namespace App\Domain\Study\Support;

use Carbon\CarbonImmutable;

final class StudyActivityAnalyticsAccumulator
{
    /** @var array<string, array{startsAt: int, endsAt: int, buckets: list<array{startsAt: int, endsAt: int}>}> */
    private array $windows;

    /** @param array<string, array<string, mixed>> $ranges */
    public function __construct(private array $ranges, CarbonImmutable $now)
    {
        $cap = StudyActivityIntervalAllocation::microseconds($now);
        $bounds = static fn (array $window): array => [
            'startsAt' => StudyActivityIntervalAllocation::microseconds(CarbonImmutable::parse($window['startsAt'])),
            'endsAt' => min($cap, StudyActivityIntervalAllocation::microseconds(CarbonImmutable::parse($window['endsAt']))),
        ];
        $this->windows = array_map(static fn (array $range): array => [
            ...$bounds($range),
            'buckets' => array_map($bounds, $range['buckets']),
        ], $ranges);
    }

    /** @return array<string, array<string, mixed>> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    public function restrictToStart(int $lowerBound): void
    {
        foreach ($this->windows as &$window) {
            if ($lowerBound > $window['startsAt']) {
                $window['startsAt'] = $lowerBound;
                $window['buckets'] = $this->clampedWindows($window['buckets'], $lowerBound);
            }
        }
        unset($window);
    }

    public function accumulate(object $session): void
    {
        // Raw database timestamps are UTC, independent of the PHP process timezone.
        $allocation = new StudyActivityIntervalAllocation(
            (int) $session->duration_ms,
            StudyActivityIntervalAllocation::microseconds(CarbonImmutable::parse($session->started_at, 'UTC')),
            StudyActivityIntervalAllocation::microseconds(CarbonImmutable::parse($session->ended_at, 'UTC')),
        );
        foreach ($this->ranges as $key => &$range) {
            $window = $this->windows[$key];
            $allocated = $allocation->forWindow($window['startsAt'], $window['endsAt']);
            if ($allocated === 0) {
                continue;
            }
            $range['totalMs'] += $allocated;
            $range['categories'][$session->category] += $allocated;
            $this->accumulateBuckets($range['buckets'], $window['buckets'], $allocation, $session->category);
        }
        unset($range);
    }

    /** @param array<string, mixed> $day */
    public function accumulateDay(array $day): void
    {
        // Closed-day service boundaries use the same fixed-width UTC wire format as ranges.
        // Compare them directly instead of reparsing thousands of already-normalized dates.
        $start = $day['startsAt'];
        $end = $day['endsAt'];
        foreach ($this->ranges as $key => &$range) {
            if ($range['bucketUnit'] === 'hour') {
                continue;
            }
            if ($start < $range['startsAt'] || $end > $range['endsAt']) {
                continue;
            }
            $index = $this->firstOverlappingBucket($range['buckets'], $start);
            if (! isset($range['buckets'][$index]) || $end > $range['buckets'][$index]['endsAt']) {
                continue;
            }
            $this->addCategories($range, $day['categories']);
            $this->addCategories($range['buckets'][$index], $day['categories']);
        }
        unset($range);
    }

    /** @param array<string, array<string, mixed>> $ranges */
    public function mergeTotals(array $ranges): void
    {
        foreach ($ranges as $key => $range) {
            $this->addCategories($this->ranges[$key], $range['categories']);
            foreach ($range['buckets'] as $index => $bucket) {
                $this->addCategories($this->ranges[$key]['buckets'][$index], $bucket['categories']);
            }
        }
    }

    /** @param array<string, mixed> $target
     * @param  array<string, int>  $categories
     */
    private function addCategories(array &$target, array $categories): void
    {
        foreach ($categories as $category => $duration) {
            $target['categories'][$category] += $duration;
            $target['totalMs'] += $duration;
        }
    }

    /** @param list<array{startsAt: int, endsAt: int}> $windows
     * @return list<array{startsAt: int, endsAt: int}>
     */
    private function clampedWindows(array $windows, int $lowerBound): array
    {
        return array_map(static fn (array $window): array => [
            'startsAt' => max($lowerBound, $window['startsAt']),
            'endsAt' => $window['endsAt'],
        ], $windows);
    }

    /**
     * @param  list<array<string, mixed>>  $buckets
     * @param  list<array{startsAt: int, endsAt: int}>  $windows
     */
    private function accumulateBuckets(array &$buckets, array $windows, StudyActivityIntervalAllocation $allocation, string $category): void
    {
        $first = $this->firstOverlappingBucket($windows, $allocation->startedAtMicroseconds);
        for ($index = $first; $index < count($windows) && $windows[$index]['startsAt'] < $allocation->recordedEndMicroseconds; $index++) {
            $window = $windows[$index];
            $duration = $allocation->forWindow($window['startsAt'], $window['endsAt']);
            $buckets[$index]['totalMs'] += $duration;
            $buckets[$index]['categories'][$category] += $duration;
        }
    }

    /** @param list<array{endsAt: int|string}> $windows */
    private function firstOverlappingBucket(array $windows, int|string $startedAt): int
    {
        $lower = 0;
        $upper = count($windows);
        while ($lower < $upper) {
            $middle = intdiv($lower + $upper, 2);
            if ($windows[$middle]['endsAt'] <= $startedAt) {
                $lower = $middle + 1;
            } else {
                $upper = $middle;
            }
        }

        return $lower;
    }
}
