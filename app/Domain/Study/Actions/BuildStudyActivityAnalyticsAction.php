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

    /** @return array{generatedAt: string, timezone: string, ranges: list<array<string, mixed>>} */
    public function handle(
        int $userId,
        DateTimeZone $timezone,
        int $weekStartsOn,
        ?CarbonImmutable $now = null,
    ): array {
        if ($weekStartsOn < 1 || $weekStartsOn > 7) {
            throw new InvalidArgumentException('The first weekday must be between 1 and 7.');
        }

        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $earliest = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->min('started_at');
        $allStart = $earliest === null
            ? $now->startOfDay()
            : CarbonImmutable::parse($earliest)->setTimezone($timezone)->startOfYear();

        $ranges = [
            self::RANGE_TODAY => $this->makeRange(
                self::RANGE_TODAY,
                $now->startOfDay(),
                $now,
                'hour',
            ),
            self::RANGE_WEEK => $this->makeRange(
                self::RANGE_WEEK,
                // Client contract follows Calendar.firstWeekday: 1=Sunday … 7=Saturday.
                $now->startOfWeek($weekStartsOn - 1),
                $now,
                'day',
            ),
            self::RANGE_MONTH => $this->makeRange(
                self::RANGE_MONTH,
                $now->startOfMonth(),
                $now,
                'day',
            ),
            self::RANGE_YEAR => $this->makeRange(
                self::RANGE_YEAR,
                $now->startOfYear(),
                $now,
                'month',
            ),
            self::RANGE_ALL => $this->makeRange(
                self::RANGE_ALL,
                $allStart,
                $now,
                'year',
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
            ->each(function (StudyActivitySession $session) use (&$ranges, $timezone, $windows): void {
                $this->accumulateSession($ranges, $windows, $session, $timezone);
            });

        return [
            'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'timezone' => $timezone->getName(),
            'ranges' => array_values($ranges),
        ];
    }

    /**
     * @return array{
     *   key: string,
     *   startsAt: string,
     *   endsAt: string,
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
    ): array {
        $buckets = [];
        $cursor = $startsAt;
        while ($cursor->lessThan($endsAt)) {
            $next = match ($bucketUnit) {
                'hour' => $cursor->addHour(),
                'day' => $cursor->addDay(),
                'month' => $cursor->addMonth(),
                'year' => $cursor->addYear(),
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
            'totalMs' => 0,
            'categories' => $this->emptyCategories(),
            'buckets' => $buckets,
        ];
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
    ): void {
        $startedAt = $session->started_at->setTimezone($timezone);
        $endedAt = $session->ended_at->setTimezone($timezone);
        $elapsedMs = max(1, (int) round($startedAt->diffInRealMilliseconds($endedAt)));
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
