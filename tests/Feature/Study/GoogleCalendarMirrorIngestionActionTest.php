<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ConnectGoogleCalendarAction;
use App\Domain\Calendar\Actions\SyncGoogleCalendarEventMirrorsAction;
use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarEventTime;
use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarMirrorIngestionActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeMirrorIngestionTransport $google;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-16T12:00:00Z');
        $this->google = new FakeMirrorIngestionTransport;
        $this->app->instance(GoogleCalendarReadTransport::class, $this->google);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_initial_and_incremental_sync_normalize_and_idempotently_upsert_every_event_shape(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, ['token_expires_at' => now()->subMinute()]);
        $this->google->grant = new GoogleCalendarTokenGrant('fresh-access', 3600, 'rotated-refresh');
        $recurring = $this->timed('instance-1', ' Lesson ', '2026-08-15T10:00:00-04:00', '2026-08-15T11:00:00-04:00', recurring: 'series-1');
        $allDay = new GoogleCalendarEvent('all-day', 'tentative', ' Holiday ',
            new GoogleCalendarEventTime('2026-08-20', null, null), new GoogleCalendarEventTime('2026-08-22', null, null),
            new GoogleCalendarEventTime('2026-08-20', null, null), '2026-08-10T12:00:00Z', 'all-day-series');
        $cancelled = new GoogleCalendarEvent('deleted', 'cancelled', null, null, null, null, '2026-08-11T12:00:00Z', null);
        $this->google->responses = [
            new GoogleCalendarPage([$recurring, $allDay], 'page-2', null),
            new GoogleCalendarPage([$cancelled], null, 'cursor-1'),
        ];

        $this->assertTrue($this->action()->handle($user->id, $connection, CarbonImmutable::parse('2026-01-01T00:00:00Z')));

        $this->assertSame(1, $this->google->refreshCalls);
        $this->assertSame('rotated-refresh', $connection->fresh()->refresh_token);
        $this->assertSame([
            ['fresh-access', 'work', '2026-01-01T00:00:00Z', null, null],
            ['fresh-access', 'work', '2026-01-01T00:00:00Z', null, 'page-2'],
        ], $this->google->calls);
        $this->assertSame([['work' => 'cursor-1'], '2026-08-16T12:00:00.000000Z'], [$connection->fresh()->sync_cursors, $connection->fresh()->last_synced_at->toJSON()]);
        $this->assertDatabaseCount('google_calendar_event_mirrors', 3);
        $seriesKey = StudyActivitySourceKey::forGoogleCalendar('account', 'work', 'series-1', '2026-08-15T10:00:00-04:00')->value;
        $series = GoogleCalendarEventMirror::query()->where('source_key', $seriesKey)->firstOrFail();
        $this->assertSame(['Lesson', '2026-08-15T14:00:00.000000Z', false], [$series->title, $series->starts_at->toJSON(), $series->all_day]);
        $allDayMirror = GoogleCalendarEventMirror::query()->where('source_key', StudyActivitySourceKey::forGoogleCalendar('account', 'work', 'all-day-series', '2026-08-20')->value)->firstOrFail();
        $this->assertSame(['2026-08-20T00:00:00.000000Z', '2026-08-22T00:00:00.000000Z', true], [
            $allDayMirror->starts_at->toJSON(), $allDayMirror->ends_at->toJSON(), $allDayMirror->all_day,
        ]);
        $this->assertNull(GoogleCalendarEventMirror::query()->where('provider_event_id', 'deleted')->value('starts_at'));

        $this->google->responses = [new GoogleCalendarPage([
            new GoogleCalendarEvent('instance-1', 'cancelled', null, null, null,
                new GoogleCalendarEventTime(null, '2026-08-15T10:00:00-04:00', null), '2026-08-16T10:00:00Z', 'series-1'),
        ], null, 'cursor-2')];
        $this->assertTrue($this->action()->handle($user->id, $connection, CarbonImmutable::parse('2026-01-01T00:00:00Z')));
        $this->assertSame([null, 'cursor-1', null], array_slice($this->google->calls[2], 2));
        $this->assertDatabaseCount('google_calendar_event_mirrors', 3);
        $this->assertSame(['cancelled', null, 'cursor-2'], [
            $series->fresh()->status, $series->fresh()->starts_at, $connection->fresh()->sync_cursors['work'],
        ]);
    }

    public function test_partial_page_failure_and_page_cap_never_advance_or_partially_persist_a_cursor(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, ['sync_cursors' => ['work' => 'old-cursor']]);
        $mirror = $this->mirror($connection, 'existing', 'Old title');
        $this->google->responses = [
            new GoogleCalendarPage([$this->timed('existing', 'Changed', '2026-08-15T10:00:00Z', '2026-08-15T11:00:00Z')], 'next', null),
            new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE),
        ];
        try {
            $this->action()->handle($user->id, $connection, CarbonImmutable::parse('2026-01-01'));
            $this->fail('Expected provider failure.');
        } catch (GoogleCalendarProviderException $exception) {
            $this->assertSame(GoogleCalendarProviderException::UNAVAILABLE, $exception->reason());
        }
        $this->assertSame(['Old title', 'old-cursor'], [$mirror->fresh()->title, $connection->fresh()->sync_cursors['work']]);

        $this->google->calls = [];
        $this->google->fallback = fn (int $call): GoogleCalendarPage => new GoogleCalendarPage([], 'page-'.$call, null);
        try {
            $this->action()->handle($user->id, $connection, CarbonImmutable::parse('2026-01-01'));
            $this->fail('Expected bounded-page rejection.');
        } catch (GoogleCalendarProviderException $exception) {
            $this->assertSame(GoogleCalendarProviderException::INVALID_RESPONSE, $exception->reason());
        }
        $this->assertCount(20, $this->google->calls);
        $this->assertSame('old-cursor', $connection->fresh()->sync_cursors['work']);
    }

    public function test_expired_cursor_clears_only_that_calendar_then_performs_one_fresh_sync(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'settings' => $this->settings(['work', 'personal']),
            'sync_cursors' => ['work' => 'expired', 'personal' => 'personal-old'],
        ]);
        $oldWork = $this->mirror($connection, 'old-work', 'Old', 'work');
        $personal = $this->mirror($connection, 'personal-event', 'Personal', 'personal');
        $this->google->responses = [
            new GoogleCalendarProviderException(GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED),
            new GoogleCalendarPage([$this->timed('new-work', 'New', '2026-08-15T10:00:00Z', '2026-08-15T11:00:00Z')], null, 'work-fresh'),
            new GoogleCalendarPage([], null, 'personal-new'),
        ];

        $this->assertTrue($this->action()->handle($user->id, $connection, CarbonImmutable::parse('2026-01-01T00:00:00Z')));

        $this->assertDatabaseMissing('google_calendar_event_mirrors', ['id' => $oldWork->id]);
        $this->assertDatabaseHas('google_calendar_event_mirrors', ['id' => $personal->id]);
        $this->assertDatabaseHas('google_calendar_event_mirrors', ['provider_event_id' => 'new-work']);
        $this->assertSame(['personal' => 'personal-new', 'work' => 'work-fresh'], $connection->fresh()->sync_cursors);
        $this->assertSame([
            ['access', 'work', null, 'expired', null],
            ['access', 'work', '2026-01-01T00:00:00Z', null, null],
            ['access', 'personal', null, 'personal-old', null],
        ], $this->google->calls);
    }

    public function test_owner_and_account_snapshot_changes_cannot_cross_or_commit_stale_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($owner);
        $otherConnection = $this->connection($other, ['provider_account_id' => 'other-account']);
        $otherMirror = $this->mirror($otherConnection, 'other-event', 'Other');
        try {
            $this->action()->handle($other->id, $connection, CarbonImmutable::parse('2026-01-01'));
            $this->fail('Expected hidden owner mismatch.');
        } catch (ModelNotFoundException) {
            $this->assertSame([], $this->google->calls);
        }

        $this->google->responses = [function () use ($owner): GoogleCalendarPage {
            app(ConnectGoogleCalendarAction::class)->handle($owner->id, new GoogleCalendarOAuthGrant(
                'new-account', 'new@example.com', 'new-access', 'new-refresh', 3600, ['calendar.readonly'],
            ));

            return new GoogleCalendarPage([$this->timed('stale', 'Stale', '2026-08-15T10:00:00Z', '2026-08-15T11:00:00Z')], null, 'stale-cursor');
        }];
        $this->assertFalse($this->action()->handle($owner->id, $connection, CarbonImmutable::parse('2026-01-01')));
        $this->assertDatabaseMissing('google_calendar_event_mirrors', ['provider_event_id' => 'stale']);
        $this->assertDatabaseHas('google_calendar_event_mirrors', ['id' => $otherMirror->id]);
        $this->assertNull($connection->fresh()->sync_cursors);
    }

    private function action(): SyncGoogleCalendarEventMirrorsAction
    {
        return app(SyncGoogleCalendarEventMirrorsAction::class);
    }

    private function connection(User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate(array_merge([
            'user_id' => $user->id, 'provider_account_id' => 'account', 'account_email' => $user->email,
            'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'], 'settings' => $this->settings(['work']), 'sync_cursors' => null,
            'connected_at' => now(), 'last_synced_at' => null,
        ], $overrides));
    }

    private function settings(array $calendars): array
    {
        return ['calendarIds' => $calendars, 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true];
    }

    private function mirror(GoogleCalendarConnection $connection, string $eventId, string $title, string $calendar = 'work'): GoogleCalendarEventMirror
    {
        return GoogleCalendarEventMirror::query()->forceCreate([
            'google_calendar_connection_id' => $connection->id,
            'source_key' => StudyActivitySourceKey::forGoogleCalendar($connection->provider_account_id, $calendar, $eventId)->value,
            'calendar_id' => $calendar, 'provider_event_id' => $eventId, 'status' => 'confirmed', 'title' => $title,
            'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(), 'all_day' => false, 'observed_at' => now(),
        ]);
    }

    private function timed(string $id, ?string $title, string $start, string $end, string $status = 'confirmed', ?string $recurring = null): GoogleCalendarEvent
    {
        return new GoogleCalendarEvent(
            $id, $status, $title, new GoogleCalendarEventTime(null, $start, null), new GoogleCalendarEventTime(null, $end, null),
            $recurring === null ? null : new GoogleCalendarEventTime(null, $start, null), '2026-08-15T12:00:00Z', $recurring,
        );
    }
}

final class FakeMirrorIngestionTransport implements GoogleCalendarReadTransport
{
    public array $responses = [];

    public array $calls = [];

    public int $refreshCalls = 0;

    public ?GoogleCalendarTokenGrant $grant = null;

    public ?\Closure $fallback = null;

    public function refresh(string $refreshToken): GoogleCalendarTokenGrant
    {
        $this->refreshCalls++;

        return $this->grant ?? new GoogleCalendarTokenGrant('refreshed', 3600, null);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        throw new \LogicException;
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        $this->calls[] = [$accessToken, $calendarId, $query->timeMin, $query->syncToken, $query->pageToken];
        $response = array_shift($this->responses);
        if ($response === null) {
            $response = $this->fallback === null
                ? throw new \LogicException('No fake response configured.')
                : ($this->fallback)(count($this->calls));
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response instanceof \Closure ? $response() : $response;
    }
}
