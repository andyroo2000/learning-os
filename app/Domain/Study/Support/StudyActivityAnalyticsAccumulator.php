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

    /** @param list<array{startsAt: int, endsAt: int}> $windows */
    private function firstOverlappingBucket(array $windows, int $startedAt): int
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
