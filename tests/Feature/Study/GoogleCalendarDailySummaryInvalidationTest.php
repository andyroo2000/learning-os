<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ReconcileGoogleCalendarStudyEventsAction;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivityDailySummaries;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Domain\Study\Support\StudyActivitySummaryWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GoogleCalendarDailySummaryInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'study_activity_daily_summaries';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-31T12:00:00Z'));
    }

    public function test_provider_create_and_reschedule_invalidate_days_but_retries_and_rename_do_not(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $mirror = $this->mirror($connection, 'lesson');
        $this->assertSame(0, $this->conversationTotal($user, '2026-08-15'));

        $this->reconcile($user, $connection);

        $this->assertDatabaseMissing(self::TABLE, ['user_id' => $user->id, 'local_date' => '2026-08-15']);
        $this->assertSame(3_600_000, $this->conversationTotal($user, '2026-08-15'));
        $beforeRetry = $this->rows();
        $this->reconcile($user, $connection);
        $mirror->forceFill(['title' => 'Renamed iTalki lesson'])->save();
        $this->reconcile($user, $connection);
        $this->assertSame($beforeRetry, $this->rows());

        $this->conversationTotal($user, '2026-08-16');
        $this->conversationTotal($user, '2026-08-17');
        $this->conversationTotal($user, '2026-08-18');
        $mirror->forceFill([
            'starts_at' => CarbonImmutable::parse('2026-08-16T23:30:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-08-17T00:30:00Z'),
        ])->save();
        $this->reconcile($user, $connection);

        $this->assertSame(['2026-08-18'], DB::table(self::TABLE)->pluck('local_date')->all());
        $this->assertSame(0, $this->conversationTotal($user, '2026-08-15'));
        $this->assertSame(1_800_000, $this->conversationTotal($user, '2026-08-16'));
        $this->assertSame(1_800_000, $this->conversationTotal($user, '2026-08-17'));
    }

    #[DataProvider('invalidMirrors')]
    public function test_provider_removal_invalidates_only_its_owners_actual_deleted_sessions(array $change): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($user);
        $otherConnection = $this->connection($other);
        $mirror = $this->mirror($connection, 'lesson');
        $this->mirror($otherConnection, 'lesson');
        $this->reconcile($user, $connection);
        $this->reconcile($other, $otherConnection);
        $this->conversationTotal($user, '2026-08-15');
        $this->conversationTotal($other, '2026-08-15');
        $mirror->forceFill($change)->save();

        $this->assertSame(['upserted' => 0, 'deleted' => 1], $this->reconcile($user, $connection));

        $this->assertSame([$other->id], DB::table(self::TABLE)->pluck('user_id')->all());
        $this->assertDatabaseMissing('study_activity_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('study_activity_sessions', ['user_id' => $other->id]);
        $this->assertSame(0, $this->conversationTotal($user, '2026-08-15'));
        $beforeRetry = $this->rows();
        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->reconcile($user, $connection));
        $this->assertSame($beforeRetry, $this->rows());
    }

    public function test_cancelled_mirror_cannot_invalidate_or_remove_a_protected_manual_session(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $mirror = $this->mirror($connection, 'protected', ['status' => 'cancelled']);
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id, 'client_session_id' => 'protected-manual',
            'source_key' => $mirror->source_key, 'source' => 'manual', 'origin' => 'google_calendar',
            'category' => 'conversation', 'activity' => 'conversation',
            'started_at' => $mirror->starts_at, 'ended_at' => $mirror->ends_at, 'duration_ms' => 600_000,
        ]);
        $this->assertSame(600_000, $this->conversationTotal($user, '2026-08-15'));
        $before = $this->rows();

        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->reconcile($user, $connection));

        $this->assertSame($before, $this->rows());
        $this->assertDatabaseHas('study_activity_sessions', ['client_session_id' => 'protected-manual']);
    }

    public function test_deduplication_invalidates_the_removed_copy_then_identical_retry_keeps_the_summary(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $this->mirror($connection, 'work-copy');
        $this->mirror($connection, 'personal-copy', ['calendar_id' => 'personal']);
        $this->reconcile($user, $connection);
        $this->selectCalendars($connection, ['personal']);
        $this->reconcile($user, $connection);
        $this->assertSame(7_200_000, $this->conversationTotal($user, '2026-08-15'));
        $this->selectCalendars($connection, ['work', 'personal']);

        $this->assertSame(['upserted' => 1, 'deleted' => 1], $this->reconcile($user, $connection));

        $this->assertDatabaseCount(self::TABLE, 0);
        $this->assertDatabaseCount('study_activity_sessions', 1);
        $this->assertSame(3_600_000, $this->conversationTotal($user, '2026-08-15'));
        $beforeRetry = $this->rows();
        $this->assertSame(['upserted' => 1, 'deleted' => 0], $this->reconcile($user, $connection));
        $this->assertSame($beforeRetry, $this->rows());
    }

    public static function invalidMirrors(): array
    {
        return ['cancelled' => [['status' => 'cancelled']], 'no-longer-matches' => [['title' => 'Dentist']]];
    }

    private function connection(User $user): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id, 'provider_account_id' => 'account-'.$user->id, 'account_email' => $user->email,
            'access_token' => 'access', 'scopes' => ['calendar.readonly'],
            'settings' => ['calendarIds' => ['work'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true],
            'connected_at' => now(),
        ]);
    }

    private function mirror(GoogleCalendarConnection $connection, string $id, array $overrides = []): GoogleCalendarEventMirror
    {
        $calendar = $overrides['calendar_id'] ?? 'work';

        return GoogleCalendarEventMirror::query()->forceCreate(array_replace([
            'google_calendar_connection_id' => $connection->id,
            'source_key' => StudyActivitySourceKey::forGoogleCalendar($connection->provider_account_id, $calendar, $id)->value,
            'calendar_id' => $calendar, 'provider_event_id' => $id, 'status' => 'confirmed',
            'title' => 'iTalki lesson', 'all_day' => false, 'observed_at' => now(),
            'starts_at' => CarbonImmutable::parse('2026-08-15T09:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-08-15T10:00:00Z'),
        ], $overrides));
    }

    private function selectCalendars(GoogleCalendarConnection $connection, array $calendars): void
    {
        $connection->forceFill(['settings' => [...$connection->settings, 'calendarIds' => $calendars]])->save();
    }

    private function reconcile(User $user, GoogleCalendarConnection $connection): array
    {
        return app(ReconcileGoogleCalendarStudyEventsAction::class)->handle($user->id, $connection);
    }

    private function conversationTotal(User $user, string $date): int
    {
        $start = CarbonImmutable::parse($date, 'UTC');
        $days = app(StudyActivityDailySummaries::class)->forWindow($user->id, new StudyActivitySummaryWindow(
            new DateTimeZone('UTC'), $start, $start->addDay(), CarbonImmutable::now('UTC'),
        ));

        return $days[0]['categories']['conversation'];
    }

    private function rows(): array
    {
        return DB::table(self::TABLE)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }
}
