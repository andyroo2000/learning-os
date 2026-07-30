<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Actions\BuildStudyActivityAnalyticsAction;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BuildStudyActivityAnalyticsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_daily_audio_as_listening_not_review(): void
    {
        $user = User::factory()->create();
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => 'daily-audio',
            'category' => 'listen',
            'activity' => 'daily_audio',
            'source' => 'automatic',
            'started_at' => '2026-07-28T11:30:00Z',
            'ended_at' => '2026-07-28T12:00:00Z',
            'duration_ms' => 1_800_000,
            'audio_playback_ms' => 1_800_000,
        ]);

        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            $user->id,
            new DateTimeZone('UTC'),
            1,
            CarbonImmutable::parse('2026-07-28T13:00:00Z'),
        );

        $today = collect($result['ranges'])->firstWhere('key', 'today');
        $this->assertSame(1_800_000, $today['categories']['listen']);
        $this->assertSame(0, $today['categories']['review']);
    }

    public function test_it_allocates_a_cross_midnight_session_between_local_day_buckets(): void
    {
        $user = User::factory()->create();
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => 'cross-midnight',
            'category' => 'conversation',
            'activity' => 'conversation',
            'source' => 'manual',
            'started_at' => '2026-07-28T03:30:00Z',
            'ended_at' => '2026-07-28T04:30:00Z',
            'duration_ms' => 3_600_000,
        ]);

        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            $user->id,
            new DateTimeZone('America/New_York'),
            1,
            CarbonImmutable::parse('2026-07-28T12:00:00-04:00'),
        );

        $today = collect($result['ranges'])->firstWhere('key', 'today');
        $week = collect($result['ranges'])->firstWhere('key', 'week');
        $this->assertSame(1_800_000, $today['totalMs']);
        $this->assertSame(1_800_000, $today['categories']['conversation']);
        $this->assertSame(3_600_000, $week['totalMs']);
        $this->assertSame(3_600_000, $week['categories']['conversation']);
    }

    public function test_it_rejects_invalid_direct_caller_week_boundaries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(BuildStudyActivityAnalyticsAction::class)->handle(
            User::factory()->create()->id,
            new DateTimeZone('UTC'),
            0,
        );
    }

    public function test_fixed_ranges_cover_the_complete_selected_calendar_period(): void
    {
        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            User::factory()->create()->id,
            new DateTimeZone('UTC'),
            2,
            CarbonImmutable::parse('2026-07-28T12:34:56Z'),
        );

        $ranges = collect($result['ranges'])->keyBy('key');

        $this->assertSame('2026-07-29T00:00:00.000000Z', $ranges['today']['endsAt']);
        $this->assertCount(24, $ranges['today']['buckets']);
        $this->assertSame('2026-07-28T23:00:00.000000Z', $ranges['today']['buckets'][23]['startsAt']);

        $this->assertSame('2026-08-03T00:00:00.000000Z', $ranges['week']['endsAt']);
        $this->assertCount(7, $ranges['week']['buckets']);
        $this->assertSame('2026-08-02T00:00:00.000000Z', $ranges['week']['buckets'][6]['startsAt']);

        $this->assertSame('2026-08-01T00:00:00.000000Z', $ranges['month']['endsAt']);
        $this->assertCount(31, $ranges['month']['buckets']);
        $this->assertSame('2026-07-31T00:00:00.000000Z', $ranges['month']['buckets'][30]['startsAt']);

        $this->assertSame('2027-01-01T00:00:00.000000Z', $ranges['year']['endsAt']);
        $this->assertCount(12, $ranges['year']['buckets']);
        $this->assertSame('2026-12-01T00:00:00.000000Z', $ranges['year']['buckets'][11]['startsAt']);

        $this->assertSame('2026-07-28T12:34:56.000000Z', $ranges['all']['endsAt']);
    }

    public function test_all_time_range_promotes_through_nice_calendar_bucket_sizes(): void
    {
        $now = CarbonImmutable::parse('2026-07-30T12:00:00Z');
        $cases = [
            ['2026-07-20T12:00:00Z', 'day', 1],
            ['2026-06-29T12:00:00Z', 'week', 1],
            ['2026-05-30T12:00:00Z', 'week', 1],
            ['2025-07-30T12:00:00Z', 'month', 1],
            ['2023-07-30T12:00:00Z', 'quarter', 1],
            ['2016-07-30T12:00:00Z', 'year', 1],
            ['1926-07-30T12:00:00Z', 'year', 5],
        ];

        foreach ($cases as $index => [$startedAt, $expectedUnit, $expectedStep]) {
            $user = User::factory()->create();
            StudyActivitySession::query()->forceCreate([
                'user_id' => $user->id,
                'client_session_id' => "adaptive-all-time-{$index}",
                'category' => 'review',
                'activity' => 'card_review',
                'source' => 'manual',
                'started_at' => $startedAt,
                'ended_at' => CarbonImmutable::parse($startedAt)->addHour(),
                'duration_ms' => 3_600_000,
            ]);

            $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
                $user->id,
                new DateTimeZone('UTC'),
                2,
                $now,
            );
            $all = collect($result['ranges'])->firstWhere('key', 'all');

            $this->assertSame($expectedUnit, $all['bucketUnit']);
            $this->assertSame($expectedStep, $all['bucketStep']);
            $this->assertLessThanOrEqual(31, count($all['buckets']));
        }
    }

    public function test_fixed_ranges_can_be_anchored_to_an_earlier_calendar_period(): void
    {
        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            User::factory()->create()->id,
            new DateTimeZone('America/New_York'),
            2,
            CarbonImmutable::parse('2026-07-28T12:34:56-04:00'),
            CarbonImmutable::parse('2026-06-15T00:00:00-04:00'),
        );

        $ranges = collect($result['ranges'])->keyBy('key');

        $this->assertSame('2026-06-15', $result['anchorDate']);
        $this->assertSame('2026-06-15T04:00:00.000000Z', $ranges['today']['startsAt']);
        $this->assertSame('2026-06-15T04:00:00.000000Z', $ranges['week']['startsAt']);
        $this->assertSame('2026-07-01T04:00:00.000000Z', $ranges['month']['endsAt']);
        $this->assertSame('2026-01-01T05:00:00.000000Z', $ranges['year']['startsAt']);
        $this->assertSame('2026-07-28T16:34:56.000000Z', $ranges['all']['endsAt']);
    }

    public function test_future_display_buckets_do_not_count_unelapsed_session_time(): void
    {
        $user = User::factory()->create();
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => 'crosses-now',
            'category' => 'review',
            'activity' => 'card_review',
            'source' => 'manual',
            'started_at' => '2026-07-28T11:30:00Z',
            'ended_at' => '2026-07-28T12:30:00Z',
            'duration_ms' => 3_600_000,
        ]);

        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            $user->id,
            new DateTimeZone('UTC'),
            2,
            CarbonImmutable::parse('2026-07-28T12:00:00Z'),
        );

        $today = collect($result['ranges'])->firstWhere('key', 'today');

        $this->assertSame(1_800_000, $today['totalMs']);
        $this->assertSame(1_800_000, $today['categories']['review']);
        $this->assertSame(1_800_000, $today['buckets'][11]['totalMs']);
        $this->assertSame(0, $today['buckets'][12]['totalMs']);
        $this->assertSame(0, $today['buckets'][23]['totalMs']);
    }

    public function test_complete_ranges_follow_local_calendar_boundaries_across_dst(): void
    {
        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            User::factory()->create()->id,
            new DateTimeZone('America/New_York'),
            1,
            CarbonImmutable::parse('2026-03-08T12:00:00-04:00'),
        );

        $ranges = collect($result['ranges'])->keyBy('key');

        $this->assertSame('2026-03-08T05:00:00.000000Z', $ranges['today']['startsAt']);
        $this->assertSame('2026-03-09T04:00:00.000000Z', $ranges['today']['endsAt']);
        $this->assertCount(23, $ranges['today']['buckets']);
        $this->assertCount(7, $ranges['week']['buckets']);
        $this->assertSame('2026-03-15T04:00:00.000000Z', $ranges['week']['endsAt']);
    }

    public function test_it_allocates_a_session_across_month_and_year_buckets(): void
    {
        $user = User::factory()->create();
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => 'cross-year',
            'category' => 'immerse',
            'activity' => 'tv',
            'source' => 'manual',
            'started_at' => '2025-12-31T23:30:00Z',
            'ended_at' => '2026-01-01T00:30:00Z',
            'duration_ms' => 3_600_000,
        ]);

        $result = app(BuildStudyActivityAnalyticsAction::class)->handle(
            $user->id,
            new DateTimeZone('UTC'),
            1,
            CarbonImmutable::parse('2026-01-02T12:00:00Z'),
        );

        $ranges = collect($result['ranges']);
        $month = $ranges->firstWhere('key', 'month');
        $year = $ranges->firstWhere('key', 'year');
        $all = $ranges->firstWhere('key', 'all');

        $this->assertSame(1_800_000, $month['totalMs']);
        $this->assertSame(1_800_000, $year['totalMs']);
        $this->assertSame('day', $all['bucketUnit']);
        $this->assertSame(
            [1_800_000, 1_800_000],
            array_slice(array_column($all['buckets'], 'totalMs'), 0, 2),
        );
        $this->assertSame(3_600_000, $all['totalMs']);
    }
}
