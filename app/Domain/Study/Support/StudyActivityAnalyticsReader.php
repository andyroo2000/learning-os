<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;

final class StudyActivityAnalyticsReader
{
    public function __construct(private readonly StudyActivityDailySummaries $summaries) {}

    /** @param array<string, array<string, mixed>> $ranges
     * @return array<string, array<string, mixed>>
     */
    public function read(int $userId, array $ranges, CarbonImmutable $now): array
    {
        $dailyRanges = array_diff_key($ranges, ['today' => true]);
        $days = $this->closedDays($userId, $dailyRanges, $now);
        $accumulator = new StudyActivityAnalyticsAccumulator($ranges, $now);
        if ($days === null) {
            $this->accumulateRaw($userId, $accumulator, $this->bounds($ranges, $now));

            return $accumulator->ranges();
        }
        foreach ($days as $day) {
            $accumulator->accumulateDay($day);
        }
        $daily = new StudyActivityAnalyticsAccumulator($dailyRanges, $now);
        $bounds = $this->bounds($dailyRanges, $now);
        if ($bounds !== null) {
            $bounds[0] = $bounds[0]->max($now->startOfDay());
            $this->accumulateRaw($userId, $daily, $bounds);
        }
        $accumulator->mergeTotals($daily->ranges());
        if (isset($ranges['today'])) {
            $hourly = new StudyActivityAnalyticsAccumulator(['today' => $ranges['today']], $now);
            $this->accumulateRaw($userId, $hourly, $this->bounds($hourly->ranges(), $now));
            $accumulator->mergeTotals($hourly->ranges());
        }

        return $accumulator->ranges();
    }

    /** @param array<string, array<string, mixed>> $ranges
     * @return list<array<string, mixed>>|null
     */
    private function closedDays(int $userId, array $ranges, CarbonImmutable $now): ?array
    {
        $bounds = $this->bounds($ranges, $now);
        if ($bounds === null) {
            return [];
        }

        if (! $this->hasStorableBoundaries($bounds, $now)) {
            return null;
        }

        return $this->summaries->forWindow($userId, new StudyActivitySummaryWindow(
            $now->getTimezone(), $bounds[0], $bounds[1], $now,
        ));
    }

    /** @param array{CarbonImmutable, CarbonImmutable} $bounds */
    private function hasStorableBoundaries(array $bounds, CarbonImmutable $now): bool
    {
        foreach ($bounds as $bound) {
            $local = $bound->setTimezone($now->getTimezone())->startOfDay();
            if ($local->year < 1 || $local->utc()->year < 1) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, array<string, mixed>> $ranges
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    private function bounds(array $ranges, CarbonImmutable $now): ?array
    {
        if ($ranges === []) {
            return null;
        }
        $start = CarbonImmutable::parse(min(array_column($ranges, 'startsAt')));
        $end = CarbonImmutable::parse(max(array_column($ranges, 'endsAt')))->min($now);

        return [$start, $end];
    }

    /** @param array{CarbonImmutable, CarbonImmutable}|null $bounds */
    private function accumulateRaw(int $userId, StudyActivityAnalyticsAccumulator $accumulator, ?array $bounds): void
    {
        if ($bounds === null || $bounds[0]->greaterThanOrEqualTo($bounds[1])) {
            return;
        }
        // Validated ancient anchors can precede PostgreSQL's AD timestamp syntax.
        // The API ledger uses AD timestamps; clip the query without changing display boundaries.
        $bounds[0] = $bounds[0]->max(CarbonImmutable::create(1, 1, 1, 0, 0, 0, 'UTC'));
        if ($bounds[0]->greaterThanOrEqualTo($bounds[1])) {
            return;
        }
        $accumulator->restrictToStart(StudyActivityIntervalAllocation::microseconds($bounds[0]));
        StudyActivitySession::query()->where('user_id', $userId)
            ->where('ended_at', '>', $bounds[0]->utc())
            ->where('started_at', '<', $bounds[1]->utc())
            ->orderBy('started_at')
            ->select(['started_at', 'ended_at', 'duration_ms', 'category'])
            ->toBase()->cursor()
            ->each(fn (object $session) => $accumulator->accumulate($session));
    }
}
