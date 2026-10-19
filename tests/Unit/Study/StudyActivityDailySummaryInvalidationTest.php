<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Actions\DeleteStudyActivitySessionAction;
use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class StudyActivityDailySummaryInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'study_activity_daily_summaries';

    private const SESSION_ID = '018f22d2-6d38-7000-8000-000000000301';

    public function test_unchanged_retries_and_nonanalytic_edits_preserve_cached_rows(): void
    {
        $user = User::factory()->create();
        $this->writeSession($user);
        $this->cacheDay($user, '2026-07-28');
        $this->cacheDay($user, '2026-07-28', 'Asia/Tokyo');
        $before = $this->summaryRows();

        $this->writeSession($user);
        $this->assertSame($before, $this->summaryRows());

        $edited = $this->writeSession($user, [
            'name' => 'Renamed session', 'audioPlaybackMs' => 100, 'cardsCreated' => 2,
        ]);

        $this->assertSame('Renamed session', $edited->name);
        $this->assertSame(100, $edited->audio_playback_ms);
        $this->assertSame(2, $edited->cards_created);
        $this->assertSame($before, $this->summaryRows());
    }

    public function test_a_moved_session_invalidates_old_and_new_local_days_across_timezones(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->writeSession($user, [
            'startedAt' => '2026-07-28T23:30:00Z', 'endedAt' => '2026-07-29T00:30:00Z',
        ]);
        $this->cacheDay($user, '2026-07-28');
        $this->cacheDay($user, '2026-07-29');
        $this->cacheDay($user, '2026-08-02');
        $this->cacheDay($user, '2026-07-28', 'America/New_York');
        $this->cacheDay($user, '2026-08-01', 'America/New_York');
        $this->cacheDay($user, '2026-07-29', 'Asia/Tokyo');
        $this->cacheDay($user, '2026-08-02', 'Asia/Tokyo');
        $untouched = $this->cacheDay($user, '2026-07-30');
        $otherOwner = $this->cacheDay($other, '2026-07-28');

        $edited = $this->writeSession($user, [
            'activity' => 'daily_audio',
            'startedAt' => '2026-08-02T01:30:00Z', 'endedAt' => '2026-08-02T02:30:00Z',
        ]);

        $this->assertSame('listen', $edited->category->value);
        $this->assertSame('2026-08-02T01:30:00+00:00', $edited->started_at->toIso8601String());
        $this->assertRemainingIds([$untouched, $otherOwner]);
    }

    public function test_category_and_duration_changes_each_invalidate_the_same_day(): void
    {
        $user = User::factory()->create();
        $this->writeSession($user);
        $this->cacheDay($user, '2026-07-28');

        $reclassified = $this->writeSession($user, ['activity' => 'wanikani_review']);

        $this->assertSame('wanikani', $reclassified->category->value);
        $this->assertDatabaseCount(self::TABLE, 0);
        $this->cacheDay($user, '2026-07-28');

        $resized = $this->writeSession($user, ['activity' => 'wanikani_review', 'durationMs' => 300_000]);

        $this->assertSame(300_000, $resized->duration_ms);
        $this->assertDatabaseCount(self::TABLE, 0);
    }

    public function test_protected_automatic_retries_and_missing_deletes_leave_summaries_untouched(): void
    {
        $user = User::factory()->create();
        $this->writeSession($user, ['source' => 'automatic']);
        $this->cacheDay($user, '2026-07-28');
        $before = $this->summaryRows();

        $protected = $this->writeSession($user, [
            'activity' => 'daily_audio', 'durationMs' => 1,
            'startedAt' => '2026-08-02T12:00:00Z', 'endedAt' => '2026-08-02T12:01:00Z',
        ]);
        $delete = app(DeleteStudyActivitySessionAction::class);

        $this->assertSame('review', $protected->category->value);
        $this->assertSame(600_000, $protected->duration_ms);
        $this->assertFalse($delete->handle($user->id, self::SESSION_ID));
        $this->assertTrue($delete->handle($user->id, '018f22d2-6d38-7000-8000-000000000302'));
        $this->assertSame($before, $this->summaryRows());
    }

    public function test_manual_delete_invalidates_only_the_owners_overlapping_summaries(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $session = $this->writeSession($user);
        $otherSession = $this->writeSession($other);
        $this->cacheDay($user, '2026-07-28');
        $this->cacheDay($user, '2026-07-28', 'Asia/Tokyo');
        $untouched = $this->cacheDay($user, '2026-07-29');
        $otherOwner = $this->cacheDay($other, '2026-07-28');
        $delete = app(DeleteStudyActivitySessionAction::class);

        $this->assertTrue($delete->handle($user->id, self::SESSION_ID));

        $this->assertDatabaseMissing('study_activity_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('study_activity_sessions', ['id' => $otherSession->id, 'user_id' => $other->id]);
        $this->assertRemainingIds([$untouched, $otherOwner]);
        $beforeRetry = $this->summaryRows();
        $this->assertTrue($delete->handle($user->id, self::SESSION_ID));
        $this->assertSame($beforeRetry, $this->summaryRows());
    }

    public function test_long_spans_delete_cached_overlaps_without_expanding_days_or_crossing_boundaries(): void
    {
        $user = User::factory()->create();
        $this->cacheDay($user, '2000-01-01');
        $this->cacheDay($user, '2010-06-01', 'America/New_York');
        $this->cacheDay($user, '2026-10-09', 'Asia/Tokyo');
        $this->cacheDay($user, '2000-01-01', 'UTC', 2);
        $beforeBoundary = $this->cacheDay($user, '1999-12-31');
        $afterBoundary = $this->cacheDay($user, '2026-10-10');
        $nonoverlap = $this->cacheDay($user, '2026-10-11', 'Asia/Tokyo');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->writeSession($user, [
                'startedAt' => '2000-01-01T00:00:00Z', 'endedAt' => '2026-10-10T00:00:00Z',
                'durationMs' => 3_600_000,
            ]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertRemainingIds([$beforeBoundary, $afterBoundary, $nonoverlap]);
        $deletes = array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete') && str_contains($query['query'], self::TABLE));
        $this->assertNotEmpty($deletes);
        $this->assertLessThanOrEqual(2, count($deletes));
        $this->assertLessThan(20, count($queries), 'Invalidation must not issue a query for every affected day.');
    }

    public function test_an_outer_rollback_restores_both_the_session_and_invalidated_days(): void
    {
        $user = User::factory()->create();
        $original = $this->writeSession($user);
        $this->cacheDay($user, '2026-07-28');
        $this->cacheDay($user, '2026-08-02');
        $before = $this->summaryRows();

        try {
            DB::transaction(function () use ($user): void {
                $this->writeSession($user, [
                    'startedAt' => '2026-08-02T12:00:00Z', 'endedAt' => '2026-08-02T12:10:00Z',
                ]);
                throw new RuntimeException('Rollback the surrounding mutation.');
            });
            $this->fail('The surrounding transaction should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback the surrounding mutation.', $exception->getMessage());
        }

        $this->assertSame($original->started_at->toIso8601String(), $original->fresh()->started_at->toIso8601String());
        $this->assertSame($before, $this->summaryRows());
    }

    private function writeSession(User $user, array $overrides = []): StudyActivitySession
    {
        $session = StudyActivitySessionData::fromValidated(array_replace([
            'clientSessionId' => self::SESSION_ID,
            'activity' => 'card_review', 'source' => 'manual', 'name' => 'Study session',
            'startedAt' => '2026-07-28T12:00:00Z', 'endedAt' => '2026-07-28T12:10:00Z',
            'durationMs' => 600_000,
        ], $overrides));

        return app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [$session])->sole();
    }

    private function cacheDay(User $user, string $date, string $timezone = 'UTC', int $version = 1): int
    {
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();

        return DB::table(self::TABLE)->insertGetId([
            'user_id' => $user->id, 'timezone' => $timezone, 'algorithm_version' => $version,
            'local_date' => $date,
            'started_at' => $start->utc()->format('Y-m-d H:i:s.u'),
            'ended_at' => $start->addDay()->utc()->format('Y-m-d H:i:s.u'),
            'categories' => json_encode([
                'review' => 600_000, 'listen' => 0, 'create' => 0,
                'immerse' => 0, 'conversation' => 0, 'wanikani' => 0,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function summaryRows(): array
    {
        return DB::table(self::TABLE)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    private function assertRemainingIds(array $expected): void
    {
        $this->assertSame($expected, DB::table(self::TABLE)->orderBy('id')->pluck('id')->all());
    }
}
