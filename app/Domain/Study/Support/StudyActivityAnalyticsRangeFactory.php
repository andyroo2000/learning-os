<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Data\StudyActivityAnalyticsOptions;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class StudyActivityAnalyticsRangeFactory
{
    /** @return array<string, array<string, mixed>> */
    public function make(int $userId, DateTimeZone $timezone, int $weekStartsOn, StudyActivityAnalyticsOptions $options): array
    {
        $now = $options->now;
        $definitions = $this->fixedDefinitions($options->anchor, $weekStartsOn);
        if (in_array('all', $options->rangeKeys(), true)) {
            $earliest = StudyActivitySession::query()->where('user_id', $userId)->min('started_at');
            $start = $earliest === null ? $now->startOfDay() : CarbonImmutable::parse($earliest, 'UTC')->setTimezone($timezone);
            [$allStart, $unit, $step] = $options->adaptiveAllTime
                ? $this->allTimeBucketDefinition($start, $now, $weekStartsOn)
                : [$start->startOfYear(), 'year', 1];
            $definitions['all'] = [$allStart, $now, ['unit' => $unit, 'step' => $step]];
        }
        $ranges = [];
        foreach ($options->rangeKeys() as $key) {
            [$start, $end, $bucket] = $definitions[$key];
            $ranges[$key] = $this->makeRange($key, $start, $end, $bucket);
        }

        return $ranges;
    }

    /** @return array<string, array{CarbonImmutable, CarbonImmutable, array{unit: string}}> */
    private function fixedDefinitions(CarbonImmutable $anchor, int $weekStartsOn): array
    {
        $day = $anchor->startOfDay();
        // Calendar.firstWeekday is 1=Sunday through 7=Saturday. Display whole periods, cap only allocation.
        $week = $anchor->startOfWeek($weekStartsOn - 1);
        $month = $anchor->startOfMonth();
        $year = $anchor->startOfYear();

        return [
            'today' => [$day, $day->addDay(), ['unit' => 'hour']],
            'week' => [$week, $week->addWeek(), ['unit' => 'day']],
            'month' => [$month, $month->addMonth(), ['unit' => 'day']],
            'year' => [$year, $year->addYear(), ['unit' => 'month']],
        ];
    }

    /**
     * @param  array{unit: string, step?: int}  $bucketDefinition
     * @return array{
     *   key: string,
     *   startsAt: string,
     *   endsAt: string,
     *   bucketUnit: string,
     *   bucketStep: int,
     *   totalMs: int,
     *   categories: array<string, int>,
     *   buckets: list<array{startsAt: string, endsAt: string, totalMs: int, categories: array<string, int>}>
     * }
     */
    private function makeRange(
        string $key,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        array $bucketDefinition,
    ): array {
        $bucketUnit = $bucketDefinition['unit'];
        $bucketStep = $bucketDefinition['step'] ?? 1;
        $buckets = [];
        $cursor = $startsAt;
        while ($cursor->lessThan($endsAt)) {
            $next = match ($bucketUnit) {
                'hour' => $cursor->addHours($bucketStep),
                'day' => $cursor->addDays($bucketStep),
                'week' => $cursor->addWeeks($bucketStep),
                'month' => $cursor->addMonths($bucketStep),
                'quarter' => $cursor->addMonths(3 * $bucketStep),
                'year' => $cursor->addYears($bucketStep),
                default => throw new InvalidArgumentException('Unsupported analytics bucket unit.'),
            };
            $bucketEnd = $next->min($endsAt);
            $buckets[] = [
                'startsAt' => $cursor->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'endsAt' => $bucketEnd->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'totalMs' => 0,
                'categories' => $this->emptyCategories(),
            ];
            $cursor = $next;
        }

        return [
            'key' => $key,
            'startsAt' => $startsAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'endsAt' => $endsAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'bucketUnit' => $bucketUnit,
            'bucketStep' => $bucketStep,
            'totalMs' => 0,
            'categories' => $this->emptyCategories(),
            'buckets' => $buckets,
        ];
    }

    /**
     * Keep all-time charts readable by promoting through calendar intervals,
     * then use unbounded 1/2/5 × 10ⁿ year steps for very long histories.
     *
     * @return array{CarbonImmutable, string, int}
     */
    private function allTimeBucketDefinition(
        CarbonImmutable $earliest,
        CarbonImmutable $now,
        int $weekStartsOn,
    ): array {
        $dayStart = $earliest->startOfDay();
        if ((int) ceil($dayStart->diffInDays($now)) <= 31) {
            return [$dayStart, 'day', 1];
        }
        $weekStart = $earliest->startOfWeek($weekStartsOn - 1);
        if ((int) ceil($weekStart->diffInDays($now) / 7) <= 16) {
            return [$weekStart, 'week', 1];
        }
        $monthStart = $earliest->startOfMonth();
        if ((int) ceil($monthStart->diffInMonths($now)) <= 24) {
            return [$monthStart, 'month', 1];
        }
        $quarterStart = $earliest->startOfQuarter();
        if ((int) ceil($quarterStart->diffInMonths($now) / 3) <= 20) {
            return [$quarterStart, 'quarter', 1];
        }

        $spanYears = max(1, $now->year - $earliest->year + 1);
        $yearStep = $spanYears <= 24
            ? 1
            : $this->niceYearStep((int) ceil($spanYears / 24));

        return [$earliest->startOfYear(), 'year', $yearStep];
    }

    private function niceYearStep(int $minimum): int
    {
        $magnitude = 10 ** (int) floor(log10(max(1, $minimum)));
        $normalized = $minimum / $magnitude;
        $factor = match (true) {
            $normalized <= 1 => 1,
            $normalized <= 2 => 2,
            $normalized <= 5 => 5,
            default => 10,
        };

        return $factor * $magnitude;
    }

    /** @return array<string, int> */
    private function emptyCategories(): array
    {
        return array_fill_keys(
            array_map(
                static fn (StudyActivityCategory $category): string => $category->value,
                StudyActivityCategory::cases(),
            ),
            0,
        );
    }
}
