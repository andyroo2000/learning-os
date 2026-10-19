<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StudyActivityAnalyticsOptions;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivityAnalyticsAccumulator;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BuildStudyActivityAnalyticsAction
{
    private const RANGE_TODAY = 'today';

    private const RANGE_WEEK = 'week';

    private const RANGE_MONTH = 'month';

    private const RANGE_YEAR = 'year';

    private const RANGE_ALL = 'all';

    /**
     * @return array{
     *   generatedAt: string,
     *   anchorDate: string,
     *   timezone: string,
     *   ranges: list<array<string, mixed>>
     * }
     */
    public function handle(
        int $userId,
        DateTimeZone $timezone,
        int $weekStartsOn,
        ?StudyActivityAnalyticsOptions $options = null,
    ): array {
        if ($weekStartsOn < 1 || $weekStartsOn > 7) {
            throw new InvalidArgumentException('The first weekday must be between 1 and 7.');
        }

        $options ??= new StudyActivityAnalyticsOptions;
        $now = ($options->now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $anchor = ($options->anchor ?? $now)->setTimezone($timezone);
        $earliest = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->min('started_at');
        $earliestStart = $earliest === null
            ? $now->startOfDay()
            : CarbonImmutable::parse($earliest, 'UTC')->setTimezone($timezone);
        $allDefinition = $options->adaptiveAllTime
            ? $this->allTimeBucketDefinition($earliestStart, $now, $weekStartsOn)
            : [$earliestStart->startOfYear(), 'year', 1];
        $ranges = $this->makeRanges($anchor, $now, $allDefinition, $weekStartsOn);
        $accumulator = new StudyActivityAnalyticsAccumulator($ranges, $now);

        StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('ended_at', '>', $allDefinition[0]->utc())
            ->where('started_at', '<', $now->utc())
            ->orderBy('started_at')
            ->select(['started_at', 'ended_at', 'duration_ms', 'category'])
            ->toBase()
            ->cursor()
            ->each(fn (object $session) => $accumulator->accumulate($session));

        return [
            'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'anchorDate' => $anchor->toDateString(),
            'timezone' => $timezone->getName(),
            'ranges' => array_values($accumulator->ranges()),
        ];
    }

    /**
     * @param  array{CarbonImmutable, string, int}  $allDefinition
     * @return array<string, array<string, mixed>>
     */
    private function makeRanges(CarbonImmutable $anchor, CarbonImmutable $now, array $allDefinition, int $weekStartsOn): array
    {
        [$allStart, $allBucketUnit, $allBucketStep] = $allDefinition;
        $todayStart = $anchor->startOfDay();
        // Display complete calendar periods; accumulation remains capped at now.
        $weekStart = $anchor->startOfWeek($weekStartsOn - 1);
        $monthStart = $anchor->startOfMonth();
        $yearStart = $anchor->startOfYear();

        return [
            self::RANGE_TODAY => $this->makeRange(
                self::RANGE_TODAY,
                $todayStart,
                $todayStart->addDay(),
                ['unit' => 'hour'],
            ),
            self::RANGE_WEEK => $this->makeRange(
                self::RANGE_WEEK,
                // Client contract follows Calendar.firstWeekday: 1=Sunday … 7=Saturday.
                $weekStart,
                $weekStart->addWeek(),
                ['unit' => 'day'],
            ),
            self::RANGE_MONTH => $this->makeRange(
                self::RANGE_MONTH,
                $monthStart,
                $monthStart->addMonth(),
                ['unit' => 'day'],
            ),
            self::RANGE_YEAR => $this->makeRange(
                self::RANGE_YEAR,
                $yearStart,
                $yearStart->addYear(),
                ['unit' => 'month'],
            ),
            self::RANGE_ALL => $this->makeRange(
                self::RANGE_ALL,
                $allStart,
                $now,
                ['unit' => $allBucketUnit, 'step' => $allBucketStep],
            ),
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
