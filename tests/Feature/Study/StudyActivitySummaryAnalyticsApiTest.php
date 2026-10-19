<?php

namespace Tests\Feature\Study;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Study\Concerns\CreatesStudyActivitySessions;
use Tests\Support\StudyActivityAnalyticsOracle;
use Tests\TestCase;

final class StudyActivitySummaryAnalyticsApiTest extends TestCase
{
    use CreatesStudyActivitySessions;
    use RefreshDatabase;

    public function test_cold_and_warm_payloads_match_original_allocation_across_dst_fractional_boundaries_and_future_tails(): void
    {
        $user = $this->signIn();
        $this->seedBoundarySessions($user);
        $processTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');
        try {
            foreach (['2026-03-08T16:00:00.123456Z', '2026-11-01T17:00:00.123456Z'] as $instant) {
                $now = CarbonImmutable::parse($instant);
                $this->travelTo($now);
                DB::table('study_activity_daily_summaries')->delete();
                foreach (['America/New_York', 'Asia/Tokyo', 'UTC'] as $timezone) {
                    $url = $this->url(['timezone' => $timezone]);
                    $cold = $this->getJson($url)->assertOk()->json();
                    $sessions = DB::table('study_activity_sessions')->where('user_id', $user->id)->get();
                    $this->assertSame(StudyActivityAnalyticsOracle::calculate($cold, $sessions, $now), $cold);
                    $this->assertSame($cold, $this->getJson($url)->assertOk()->json());
                }
            }
        } finally {
            date_default_timezone_set($processTimezone);
        }
    }

    public function test_historical_hourly_details_remain_exact_and_warm_week_reads_do_not_touch_the_ledger(): void
    {
        $user = $this->signIn();
        $this->seedBoundarySessions($user);
        $now = CarbonImmutable::parse('2026-11-04T17:00:00Z');
        $this->travelTo($now);
        $full = $this->getJson($this->url(['anchorDate' => '2026-11-01']))->assertOk()->json();
        $sessions = DB::table('study_activity_sessions')->where('user_id', $user->id)->get();
        $this->assertSame(StudyActivityAnalyticsOracle::calculate($full, $sessions, $now), $full);
        $weekUrl = $this->url(['anchorDate' => '2026-03-08', 'ranges' => ['week']]);
        $this->getJson($weekUrl)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($weekUrl)->assertOk()->assertJsonCount(1, 'ranges');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $ledgerQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'study_activity_sessions'));
        $this->assertCount(0, $ledgerQueries);
    }

    public function test_range_selection_is_validated_canonical_and_compatible_when_omitted(): void
    {
        $this->signIn();
        $this->getJson($this->url())->assertOk()->assertJsonCount(5, 'ranges');
        $this->getJson($this->url(['ranges' => ['month', 'week']]))->assertOk()
            ->assertJsonCount(2, 'ranges')->assertJsonPath('ranges.0.key', 'week')->assertJsonPath('ranges.1.key', 'month');
        foreach (['week', ['unknown'], ['week', 'week'], ['week', 'month', 'year', 'all', 'today', 'week'], ['name' => 'week']] as $invalid) {
            $this->getJson($this->url(['ranges' => $invalid]))->assertUnprocessable();
        }
        $this->getJson($this->url().'&ranges[]=')->assertUnprocessable();
    }

    public function test_very_long_history_falls_back_exactly_without_materializing_daily_summaries(): void
    {
        $user = $this->signIn();
        $this->createSession($user, ['started_at' => '1900-01-01T00:00:00Z', 'ended_at' => '1900-01-02T00:00:00Z']);
        $now = CarbonImmutable::parse('2026-10-19T16:00:00Z');
        $this->travelTo($now);
        $response = $this->getJson($this->url())->assertOk()->json();
        $sessions = DB::table('study_activity_sessions')->where('user_id', $user->id)->get();
        $this->assertSame(StudyActivityAnalyticsOracle::calculate($response, $sessions, $now), $response);
        $this->assertDatabaseCount('study_activity_daily_summaries', 0);
        $this->getJson($this->url(['ranges' => ['week'], 'anchorDate' => '1800-01-01']))->assertOk()
            ->assertJsonPath('ranges.0.totalMs', 0);
        $this->assertDatabaseCount('study_activity_daily_summaries', 7);
    }

    public function test_ancient_narrow_anchors_keep_empty_results_without_unrepresentable_cache_dates(): void
    {
        $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T16:00:00Z'));
        foreach (['0000-01-01', '0001-01-01'] as $anchor) {
            foreach (['UTC', 'Asia/Tokyo'] as $timezone) {
                $this->getJson($this->url(['ranges' => ['week'], 'anchorDate' => $anchor, 'timezone' => $timezone]))
                    ->assertOk()->assertJsonPath('ranges.0.totalMs', 0);
            }
        }
        $this->assertDatabaseCount('study_activity_daily_summaries', 0);
    }

    private function seedBoundarySessions(User $user): void
    {
        foreach ([
            ['2026-03-08T04:59:59.999400Z', '2026-03-09T04:00:00.000900Z', 83_123_457],
            ['2026-10-31T03:59:59.999400Z', '2026-11-01T05:00:00.000900Z', 71_234_569],
            ['2026-11-01T03:59:59.999400Z', '2026-11-03T06:00:00.000900Z', 91_234_571],
        ] as [$start, $end, $duration]) {
            $session = $this->createSession($user, ['duration_ms' => $duration]);
            // Preserve microseconds even on databases/models that serialize dates at second precision.
            DB::table('study_activity_sessions')->where('id', $session->id)->update([
                'started_at' => CarbonImmutable::parse($start)->format('Y-m-d H:i:s.u'),
                'ended_at' => CarbonImmutable::parse($end)->format('Y-m-d H:i:s.u'),
            ]);
        }
        $this->createSession(User::factory()->create(), ['duration_ms' => 999_999]);
    }

    private function url(array $query = []): string
    {
        return '/api/study/activity-analytics?'.http_build_query(array_merge([
            'timezone' => 'America/New_York', 'weekStartsOn' => 1, 'adaptiveAllTime' => 1,
        ], $query));
    }
}
