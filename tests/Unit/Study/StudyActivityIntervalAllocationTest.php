<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyActivityDurationAllocator;
use App\Domain\Study\Support\StudyActivityIntervalAllocation;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class StudyActivityIntervalAllocationTest extends TestCase
{
    public function test_numeric_allocation_matches_the_original_for_fractional_boundaries_and_timezones(): void
    {
        foreach ([
            '1969-12-31T23:59:59.999501Z',
            '2026-03-08T01:59:59.999499-05:00',
            '2026-11-01T01:59:59.999501-04:00',
            '2026-10-19T01:05:06.000499+05:45',
            '2038-01-19T03:14:07.123456Z',
        ] as $timestamp) {
            $start = CarbonImmutable::parse($timestamp);
            for ($seed = 0; $seed < 160; $seed++) {
                $span = $seed * 73_097;
                $this->assertEquivalent([
                    'durationMs' => $seed * 541 % 7_001,
                    'start' => $start,
                    'end' => $start->addMicroseconds($span),
                    'now' => $start->addMicroseconds(intdiv($span * ($seed % 3 + 1), 2)),
                    'windowStart' => $start->addMicroseconds(($seed % 5 - 1) * 499),
                    'windowEnd' => $start->addMicroseconds(intdiv($span, 2) + 501),
                ]);
            }
        }
    }

    public function test_adjacent_windows_conserve_duration_and_exclude_touching_endpoints(): void
    {
        $allocation = new StudyActivityIntervalAllocation(1_000, 499, 3_000_499);

        $this->assertSame(0, $allocation->forWindow(-1_000, 499));
        $this->assertSame(0, $allocation->forWindow(3_000_499, 4_000_000));
        $this->assertSame([333, 334, 333], [
            $allocation->forWindow(499, 1_000_499),
            $allocation->forWindow(1_000_499, 2_000_499),
            $allocation->forWindow(2_000_499, 3_000_499),
        ]);
    }

    public function test_window_cap_uses_the_original_span_as_the_denominator(): void
    {
        $allocation = new StudyActivityIntervalAllocation(3_600_001, 0, 3_600_000_000);

        $this->assertSame(1_800_001, $allocation->forWindow(0, 1_800_000_000));
        $this->assertSame(0, $allocation->forWindow(2_000_000_000, 1_800_000_000));
    }

    /** @param array{durationMs: int, start: CarbonImmutable, end: CarbonImmutable, now: CarbonImmutable, windowStart: CarbonImmutable, windowEnd: CarbonImmutable} $case */
    private function assertEquivalent(array $case): void
    {
        $original = (new StudyActivityDurationAllocator)->forWindow(
            $case['durationMs'], $case['start'], $case['end']->min($case['now']),
            $case['windowStart'], $case['windowEnd'], $case['end'],
        );
        $numeric = new StudyActivityIntervalAllocation(
            $case['durationMs'],
            StudyActivityIntervalAllocation::microseconds($case['start']),
            StudyActivityIntervalAllocation::microseconds($case['end']),
        );
        $this->assertSame($original, $numeric->forWindow(
            StudyActivityIntervalAllocation::microseconds($case['windowStart']),
            StudyActivityIntervalAllocation::microseconds($case['windowEnd']->min($case['now'])),
        ));
    }
}
