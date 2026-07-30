<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Models\StudyActivitySession;
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
        ?CarbonImmutable $now = null,
        ?CarbonImmutable $anchor = null,
    ): array {
        if ($weekStartsOn < 1 || $weekStartsOn > 7) {
            throw new InvalidArgumentException('The first weekday must be between 1 and 7.');
        }

        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $anchor = ($anchor ?? $now)->setTimezone($timezone);
        $earliest = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->min('started_at');
        $earliestStart = $earliest === null
            ? $now->startOfDay()
            : CarbonImmutable::parse($earliest)->setTimezone($timezone);
        [$allStart, $allBucketUnit, $allBucketStep] = $this->allTimeBucketDefinition(
            $earliestStart,
            $now,
            $weekStartsOn,
        );
        $todayStart = $anchor->startOfDay();
        // Fixed calendar ranges include their complete future-facing display
        // window. Session accumulation remains capped at $now below.
        $weekStart = $anchor->startOfWeek($weekStartsOn - 1);
        $monthStart = $anchor->startOfMonth();
        $yearStart = $anchor->startOfYear();

        $ranges = [
            self::RANGE_TODAY => $this->makeRange(
                self::RANGE_TODAY,
                $todayStart,
                $todayStart->addDay(),
                'hour',
            ),
            self::RANGE_WEEK => $this->makeRange(
                self::RANGE_WEEK,
                // Client contract follows Calendar.firstWeekday: 1=Sunday … 7=Saturday.
                $weekStart,
                $weekStart->addWeek(),
                'day',
            ),
            self::RANGE_MONTH => $this->makeRange(
                self::RANGE_MONTH,
                $monthStart,
                $monthStart->addMonth(),
                'day',
            ),
            self::RANGE_YEAR => $this->makeRange(
                self::RANGE_YEAR,
                $yearStart,
                $yearStart->addYear(),
                'month',
            ),
            self::RANGE_ALL => $this->makeRange(
                self::RANGE_ALL,
                $allStart,
                $now,
                $allBucketUnit,
                $allBucketStep,
            ),
        ];
        $windows = array_map(
            fn (array $range): array => [
                'startsAt' => CarbonImmutable::parse($range['startsAt'])->setTimezone($timezone),
                'endsAt' => CarbonImmutable::parse($range['endsAt'])->setTimezone($timezone),
                'buckets' => array_map(
                    fn (array $bucket): array => [
                        'startsAt' => CarbonImmutable::parse($bucket['startsAt'])
                            ->setTimezone($timezone),
                        'endsAt' => CarbonImmutable::parse($bucket['endsAt'])
                            ->setTimezone($timezone),
                    ],
                    $range['buckets'],
                ),
            ],
            $ranges,
        );

        StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('ended_at', '>', $allStart->utc())
            ->where('started_at', '<', $now->utc())
            ->orderBy('started_at')
            ->cursor()
            ->each(function (StudyActivitySession $session) use (
                &$ranges,
                $now,
                $timezone,
                $windows,
            ): void {
                $this->accumulateSession($ranges, $windows, $session, $timezone, $now);
            });

        return [
            'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'anchorDate' => $anchor->toDateString(),
            'timezone' => $timezone->getName(),
            'ranges' => array_values($ranges),
        ];
    }

    /**
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
        string $bucketUnit,
        int $bucketStep = 1,
    ): array {
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

    /**
     * @param  array<string, array<string, mixed>>  $ranges
     * @param  array<string, array{startsAt: CarbonImmutable, endsAt: CarbonImmutable, buckets: list<array{startsAt: CarbonImmutable, endsAt: CarbonImmutable}>}>  $windows
     */
    private function accumulateSession(
        array &$ranges,
        array $windows,
        StudyActivitySession $session,
        DateTimeZone $timezone,
        CarbonImmutable $now,
    ): void {
        $startedAt = $session->started_at->setTimezone($timezone);
        $recordedEnd = $session->ended_at->setTimezone($timezone);
        $elapsedMs = max(1, (int) round($startedAt->diffInRealMilliseconds($recordedEnd)));
        $endedAt = $recordedEnd->min($now);
        $category = $session->category->value;

        foreach ($ranges as $rangeKey => &$range) {
            $window = $windows[$rangeKey];
            $allocated = $this->allocatedDuration(
                $session->duration_ms,
                $elapsedMs,
                $startedAt,
                $endedAt,
                $window['startsAt'],
                $window['endsAt'],
            );
            if ($allocated === 0) {
                continue;
            }

            $range['totalMs'] += $allocated;
            $range['categories'][$category] += $allocated;
            foreach ($range['buckets'] as $bucketIndex => &$bucket) {
                $bucketWindow = $window['buckets'][$bucketIndex];
                $bucketAllocation = $this->allocatedDuration(
                    $session->duration_ms,
                    $elapsedMs,
                    $startedAt,
                    $endedAt,
                    $bucketWindow['startsAt'],
                    $bucketWindow['endsAt'],
                );
                if ($bucketAllocation === 0) {
                    continue;
                }
                $bucket['totalMs'] += $bucketAllocation;
                $bucket['categories'][$category] += $bucketAllocation;
            }
            unset($bucket);
        }
        unset($range);
    }

    private function allocatedDuration(
        int $durationMs,
        int $elapsedMs,
        CarbonImmutable $sessionStart,
        CarbonImmutable $sessionEnd,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): int {
        $overlapStart = $sessionStart->max($windowStart);
        $overlapEnd = $sessionEnd->min($windowEnd);
        if ($overlapEnd->lessThanOrEqualTo($overlapStart)) {
            return 0;
        }

        $overlapMs = (int) round($overlapStart->diffInRealMilliseconds($overlapEnd));

        return (int) round($durationMs * min(1, $overlapMs / $elapsedMs));
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
