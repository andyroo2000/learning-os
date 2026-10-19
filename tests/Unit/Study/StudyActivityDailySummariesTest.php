<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyActivityDailySummaries;
use App\Domain\Study\Support\StudyActivityDurationAllocator;
use App\Domain\Study\Support\StudyActivitySummaryWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class StudyActivityDailySummariesTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'study_activity_daily_summaries';

    public function test_warm_reads_reuse_zero_and_active_days_without_reading_the_ledger_or_writing(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, ['started_at' => '2026-10-16 12:00:00.000000', 'ended_at' => '2026-10-16 13:00:00.000000']);
        $window = $this->window('2026-10-15', '2026-10-18');
        $service = app(StudyActivityDailySummaries::class);
        $cold = $service->forWindow($user->id, $window);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $warm = $service->forWindow($user->id, $window);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($cold, $warm);
        $this->assertSame([0, 3_600_000, 0], array_map(fn (array $day): int => array_sum($day['categories']), $warm));
        $this->assertDatabaseCount(self::TABLE, 3);
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringNotContainsString('study_activity_sessions', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete)\b/i', $sql);
    }

    #[DataProvider('allocations')]
    public function test_daily_totals_match_the_original_allocator_at_midnight_and_dst_boundaries(array $fixture): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, $fixture['session']);
        $window = $this->window($fixture['start'], $fixture['end'], $fixture['timezone']);
        $days = app(StudyActivityDailySummaries::class)->forWindow($user->id, $window);
        $allocator = new StudyActivityDurationAllocator;
        $start = CarbonImmutable::parse($fixture['session']['started_at'], 'UTC');
        $end = CarbonImmutable::parse($fixture['session']['ended_at'], 'UTC');
        foreach ($days as $day) {
            $expected = $allocator->forWindow($fixture['session']['duration_ms'], $start, $end,
                CarbonImmutable::parse($day['startsAt']), CarbonImmutable::parse($day['endsAt']));
            $this->assertSame($expected, $day['categories']['review']);
        }
    }

    public static function allocations(): iterable
    {
        yield 'fractional split conserves credit' => [[
            'start' => '2026-10-17', 'end' => '2026-10-19', 'timezone' => 'America/New_York',
            'session' => ['started_at' => '2026-10-18 03:59:59.999750', 'ended_at' => '2026-10-18 04:00:00.004250', 'duration_ms' => 3],
        ]];
        yield '23-hour day' => [[
            'start' => '2026-03-07', 'end' => '2026-03-10', 'timezone' => 'America/New_York',
            'session' => ['started_at' => '2026-03-07 12:00:00.000000', 'ended_at' => '2026-03-10 12:00:00.000000', 'duration_ms' => 86_400_000],
        ]];
        yield '25-hour day' => [[
            'start' => '2026-11-01', 'end' => '2026-11-03', 'timezone' => 'America/New_York',
            'session' => ['started_at' => '2026-11-01 03:00:00.000000', 'ended_at' => '2026-11-03 06:00:00.000000', 'duration_ms' => 86_400_000],
        ]];
        yield 'future end keeps original denominator' => [[
            'start' => '2026-10-17', 'end' => '2026-10-19', 'timezone' => 'UTC',
            'session' => ['started_at' => '2026-10-17 00:00:00.000000', 'ended_at' => '2027-10-19 00:00:00.000000', 'duration_ms' => 3_600_000],
        ]];
    }

    public function test_timezone_and_account_scoping_do_not_depend_on_the_process_timezone(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->insertSession($user, ['started_at' => '2026-10-18 03:30:00.000000', 'ended_at' => '2026-10-18 04:30:00.000000']);
        $raw = DB::table('study_activity_sessions')->first();
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $service = app(StudyActivityDailySummaries::class);
            $newYork = $service->forWindow($user->id, $this->window('2026-10-17', '2026-10-19', 'America/New_York'));
            $tokyo = $service->forWindow($user->id, $this->window('2026-10-17', '2026-10-19', 'Asia/Tokyo'));
            $otherDays = $service->forWindow($other->id, $this->window('2026-10-17', '2026-10-19', 'America/New_York'));
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame([1_800_000, 1_800_000], array_column(array_column($newYork, 'categories'), 'review'));
        $this->assertSame([0, 3_600_000], array_column(array_column($tokyo, 'categories'), 'review'));
        $this->assertSame([0, 0], array_column(array_column($otherDays, 'categories'), 'review'));
        $this->assertEquals($raw, DB::table('study_activity_sessions')->first(), 'Summaries must not rewrite original timestamps.');
    }

    public function test_rebuilding_a_missing_day_limits_the_ledger_query_to_that_day(): void
    {
        $user = User::factory()->create();
        $service = app(StudyActivityDailySummaries::class);
        $window = $this->window('2026-10-01', '2026-10-19');
        $original = $service->forWindow($user->id, $window);
        DB::table(self::TABLE)->where('local_date', '2026-10-09')->delete();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $rebuilt = $service->forWindow($user->id, $window);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($original, $rebuilt);
        $ledger = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'study_activity_sessions')));
        $this->assertCount(1, $ledger);
        $this->assertSame([$user->id, '2026-10-09 00:00:00.000000', '2026-10-10 00:00:00.000000'], $ledger[0]['bindings']);
        $this->assertDatabaseCount(self::TABLE, 18);
    }

    public function test_empty_or_excessive_windows_do_not_touch_the_database(): void
    {
        $service = app(StudyActivityDailySummaries::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame([], $service->forWindow(999, $this->window('2026-10-19', '2026-10-19')));
            $this->assertNull($service->forWindow(999, $this->window('1900-01-01', '2026-10-19')));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_a_failed_outer_transaction_does_not_leave_a_partial_cache(): void
    {
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($user): void {
                app(StudyActivityDailySummaries::class)->forWindow($user->id, $this->window('2026-10-17', '2026-10-19'));
                $this->assertDatabaseCount(self::TABLE, 2);
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('abort', $error->getMessage());
        }
        $this->assertDatabaseCount(self::TABLE, 0);
    }

    private function window(string $start, string $end, string $timezone = 'UTC'): StudyActivitySummaryWindow
    {
        return new StudyActivitySummaryWindow(new DateTimeZone($timezone), CarbonImmutable::parse($start, $timezone),
            CarbonImmutable::parse($end, $timezone), CarbonImmutable::parse($end, $timezone)->addDay());
    }

    private function insertSession(User $user, array $overrides): void
    {
        DB::table('study_activity_sessions')->insert(array_replace([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'client_session_id' => (string) Str::uuid(),
            'category' => 'review', 'activity' => 'card_review', 'source' => 'manual', 'origin' => 'ios',
            'duration_ms' => 3_600_000, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
