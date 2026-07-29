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
        $this->assertSame([1_800_000, 1_800_000], array_column($all['buckets'], 'totalMs'));
        $this->assertSame(3_600_000, $all['totalMs']);
    }
}
