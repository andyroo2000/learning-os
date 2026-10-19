<?php

namespace Tests\Support;

use App\Domain\Study\Support\StudyActivityDurationAllocator;
use Carbon\CarbonImmutable;

final class StudyActivityAnalyticsOracle
{
    /** Uses the original Carbon allocator independently of daily summaries and numeric accumulation. */
    public static function calculate(array $template, iterable $sessions, CarbonImmutable $now): array
    {
        self::clearRanges($template);
        foreach ($sessions as $session) {
            foreach ($template['ranges'] as &$range) {
                self::add($range, $session, $now);
                foreach ($range['buckets'] as &$bucket) {
                    self::add($bucket, $session, $now);
                }
                unset($bucket);
            }
            unset($range);
        }

        return $template;
    }

    private static function clearRanges(array &$template): void
    {
        foreach ($template['ranges'] as &$range) {
            self::clear($range);
            foreach ($range['buckets'] as &$bucket) {
                self::clear($bucket);
            }
            unset($bucket);
        }
        unset($range);
    }

    private static function clear(array &$window): void
    {
        $window['totalMs'] = 0;
        $window['categories'] = array_fill_keys(array_keys($window['categories']), 0);
    }

    private static function add(array &$window, object $session, CarbonImmutable $now): void
    {
        $end = CarbonImmutable::parse($session->ended_at, 'UTC');
        $duration = (new StudyActivityDurationAllocator)->forWindow(
            (int) $session->duration_ms,
            CarbonImmutable::parse($session->started_at, 'UTC'),
            $end->min($now),
            CarbonImmutable::parse($window['startsAt']),
            CarbonImmutable::parse($window['endsAt']),
            $end,
        );
        $window['totalMs'] += $duration;
        $window['categories'][$session->category] += $duration;
    }
}
