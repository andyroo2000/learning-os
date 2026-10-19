<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyActivitySummaryWindow;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StudyActivitySummaryWindowTest extends TestCase
{
    #[DataProvider('localDays')]
    public function test_calendar_days_follow_timezone_transitions(string $date, string $timezone, int $hours): void
    {
        $start = CarbonImmutable::parse($date, $timezone);
        $window = new StudyActivitySummaryWindow(new DateTimeZone($timezone), $start, $start->addDay(), $start->addDays(2));
        $day = $window->days()[0];

        $this->assertCount(1, $window->days());
        $this->assertSame($date, $day['date']);
        $this->assertSame($hours * 3_600_000_000, $day['endUs'] - $day['startUs']);
        $this->assertSame('UTC', $day['start']->timezoneName);
        $this->assertSame('UTC', $day['end']->timezoneName);
    }

    public static function localDays(): iterable
    {
        yield 'spring forward' => ['2026-03-08', 'America/New_York', 23];
        yield 'fall back' => ['2026-11-01', 'America/New_York', 25];
        yield 'fractional offset' => ['2026-10-18', 'Asia/Kathmandu', 24];
    }

    public function test_today_and_future_days_are_excluded_until_local_midnight(): void
    {
        $start = CarbonImmutable::parse('2026-10-18', 'America/New_York');
        $end = $start->addWeek();
        $before = new StudyActivitySummaryWindow(new DateTimeZone('America/New_York'), $start, $end, $start->endOfDay());
        $after = new StudyActivitySummaryWindow(new DateTimeZone('America/New_York'), $start, $end, $start->addDay());

        $this->assertSame([], $before->days());
        $this->assertSame(['2026-10-18'], array_column($after->days(), 'date'));
    }

    public function test_exceptionally_long_histories_request_fallback_before_expanding_dates(): void
    {
        $now = CarbonImmutable::parse('2026-10-19T12:00:00Z');
        $window = new StudyActivitySummaryWindow(new DateTimeZone('UTC'), $now->subYears(500), $now, $now);
        $this->assertNull($window->days());

        $future = new StudyActivitySummaryWindow(new DateTimeZone('UTC'), $now->addDay(), $now->addWeek(), $now);
        $this->assertSame([], $future->days());
    }
}
