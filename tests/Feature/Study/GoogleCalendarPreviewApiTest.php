<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendar;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarEventTime;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleCalendarPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarPreviewTransport $google;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
        $this->google = new FakeCalendarPreviewTransport;
        $this->app->instance(GoogleCalendarReadTransport::class, $this->google);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preview_requires_auth_and_connection_and_has_an_exact_canonical_body(): void
    {
        $url = '/api/study/google-calendar/preview';
        $body = $this->body();
        $this->postJson($url, $body)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson($url, $body)->assertStatus(409)->assertExactJson([
            'error' => ['code' => 'not_connected', 'message' => 'Connect Google Calendar before continuing.'],
        ]);
        $this->connection($user);
        $this->postJson($url, $body + ['extra' => true])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->postJson($url, ['calendarIds' => []])->assertUnprocessable()->assertJsonValidationErrors(['calendarIds', 'titleMatchTerms']);
        $this->postJson($url, ['calendarIds' => array_fill(0, 26, 'x'), 'titleMatchTerms' => ['x']])->assertUnprocessable();
        $this->postJson($url, ['calendarIds' => [str_repeat('x', 1025)], 'titleMatchTerms' => ['x']])->assertUnprocessable();
        $this->postJson($url, ['calendarIds' => ['named' => 'x'], 'titleMatchTerms' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('calendarIds');
        $this->postJson($url, ['calendarIds' => ['x'], 'titleMatchTerms' => ['named' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('titleMatchTerms');
        $this->postJson($url, ['calendarIds' => ['x'], 'titleMatchTerms' => array_fill(0, 51, 'x')])->assertUnprocessable();
        $this->postJson($url, ['calendarIds' => ['x'], 'titleMatchTerms' => [str_repeat('会', 101)]])->assertUnprocessable();
        $this->google->calendars = [new GoogleCalendar('work', 'Work', false, 'reader')];
        $this->withoutMiddleware(TrimStrings::class)->postJson($url, [
            'calendarIds' => ["\u{3000}work\u{00a0}", 'work'],
            'titleMatchTerms' => ["\u{3000}iTalki ", 'ITALKI'],
        ])->assertOk();
        $this->assertSame('work', $this->google->eventCalls[0][1]);
    }

    public function test_preview_filters_matches_pages_sorts_and_marks_only_owner_sessions_synced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->connection($owner, 'account');
        $key = StudyActivitySourceKey::forGoogleCalendar('account', 'work', 'one');
        $this->createSession($owner, $key->value);
        $this->createSession($other, StudyActivitySourceKey::forGoogleCalendar('account', 'work', 'recurring', '2026-08-14T12:00:00Z')->value);
        $this->google->calendars = [new GoogleCalendar('work', 'Work calendar', false, 'owner')];
        $this->google->eventPages = [
            new GoogleCalendarPage([
                $this->event('one', ' iTalki 日本語 ', '2026-08-14T10:00:00Z', '2026-08-14T11:00:00Z'),
                $this->event('cancelled', 'iTalki', '2026-08-14T09:00:00Z', '2026-08-14T10:00:00Z', 'cancelled'),
                new GoogleCalendarEvent('all-day', 'confirmed', 'iTalki', new GoogleCalendarEventTime('2026-08-14', null, null), new GoogleCalendarEventTime('2026-08-15', null, null), null, null, null),
                $this->event('future', 'iTalki', '2026-08-15T11:00:00Z', '2026-08-15T13:00:00Z'),
                $this->event('overlap', 'iTalki boundary', '2026-07-15T11:30:00Z', '2026-07-15T12:30:00Z'),
            ], 'page-2', null),
            new GoogleCalendarPage([
                $this->event('instance', '日本語 ITALKI', '2026-08-14T12:00:00+00:00', '2026-08-14T12:30:00+00:00', recurring: 'recurring'),
                $this->event('long', 'iTalki', '2026-08-12T00:00:00Z', '2026-08-14T00:00:01Z'),
                $this->event('bad', 'iTalki', '2026-02-31T00:00:00Z', '2026-08-14T01:00:00Z'),
                $this->event('blank', '   ', '2026-08-14T01:00:00Z', '2026-08-14T02:00:00Z'),
            ], null, 'secret-sync-token'),
        ];
        $queries = 0;
        $connectionQueries = 0;
        DB::listen(static function ($query) use (&$queries, &$connectionQueries): void {
            if (str_contains($query->sql, 'study_activity_sessions')) {
                $queries++;
            }
            if (str_starts_with(trim($query->sql), 'select') && str_contains($query->sql, 'google_calendar_connections')) {
                $connectionQueries++;
            }
        });

        $response = $this->actingAs($owner)->postJson('/api/study/google-calendar/preview', [
            'calendarIds' => ['work'], 'titleMatchTerms' => ['iTalki', '日本語'],
        ])->assertOk()->json();

        $this->assertSame(['2026-07-15T12:00:00Z', '2026-08-15T12:00:00Z', null, null, 250], $this->google->eventCalls[0][2]);
        $this->assertSame(['2026-07-15T12:00:00Z', '2026-08-15T12:00:00Z', null, 'page-2', 250], $this->google->eventCalls[1][2]);
        $this->assertSame(9, $response['scannedEventCount']);
        $this->assertSame(3, $response['matchedEventCount']);
        $this->assertFalse($response['truncated']);
        $this->assertSame(['2026-08-15T12:00:00Z', '2026-07-15T12:00:00Z', '2026-08-15T12:00:00Z'], [$response['generatedAt'], $response['startsAt'], $response['endsAt']]);
        $this->assertSame(['generatedAt', 'startsAt', 'endsAt', 'scannedEventCount', 'matchedEventCount', 'truncated', 'matches'], array_keys($response));
        $this->assertSame([
            ['calendarId' => 'work', 'calendarName' => 'Work calendar', 'title' => '日本語 ITALKI', 'startsAt' => '2026-08-14T12:00:00Z', 'endsAt' => '2026-08-14T12:30:00Z', 'durationMs' => 1_800_000, 'matchedTerms' => ['iTalki', '日本語'], 'alreadySynced' => false],
            ['calendarId' => 'work', 'calendarName' => 'Work calendar', 'title' => 'iTalki 日本語', 'startsAt' => '2026-08-14T10:00:00Z', 'endsAt' => '2026-08-14T11:00:00Z', 'durationMs' => 3_600_000, 'matchedTerms' => ['iTalki', '日本語'], 'alreadySynced' => true],
            ['calendarId' => 'work', 'calendarName' => 'Work calendar', 'title' => 'iTalki boundary', 'startsAt' => '2026-07-15T11:30:00Z', 'endsAt' => '2026-07-15T12:30:00Z', 'durationMs' => 3_600_000, 'matchedTerms' => ['iTalki'], 'alreadySynced' => false],
        ], $response['matches']);
        $this->assertSame(1, $queries);
        $this->assertSame(2, $connectionQueries);
        $this->assertSame([], GoogleCalendarConnection::query()->where('user_id', $owner->id)->value('settings'));
        $this->assertSame(2, StudyActivitySession::query()->count());
    }

    public function test_preview_has_bounded_counts_truncation_and_safe_failures(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $this->google->calendars = [new GoogleCalendar('work', 'Work', false, 'writer')];
        $events = [];
        for ($i = 0; $i < 201; $i++) {
            $events[] = $this->event("event-$i", 'lesson', '2026-08-14T10:00:00Z', '2026-08-14T10:01:00Z');
        }
        $this->google->eventPages = [new GoogleCalendarPage($events, null, null)];
        $body = $this->body();
        $this->actingAs($user)->postJson('/api/study/google-calendar/preview', $body)
            ->assertJsonPath('scannedEventCount', 201)->assertJsonPath('matchedEventCount', 201)
            ->assertJsonPath('truncated', true)->assertJsonCount(200, 'matches');

        $this->google->calendars[] = new GoogleCalendar('other', 'Other', false, 'reader');
        $body['calendarIds'][] = 'other';
        $this->google->eventPages = array_fill(0, 9, new GoogleCalendarPage([], 'next', null));
        $this->postJson('/api/study/google-calendar/preview', $body)->assertJsonPath('truncated', true);
        $this->assertCount(9, $this->google->eventCalls); // One prior request plus eight bounded requests.

        $this->google->calendars = [];
        $this->postJson('/api/study/google-calendar/preview', $body)->assertStatus(422)->assertExactJson([
            'error' => ['code' => 'calendar_unavailable', 'message' => 'One or more selected calendars are unavailable.'],
        ]);
        $this->google->calendars = [new GoogleCalendar('work', 'Work', false, 'reader'), new GoogleCalendar('other', 'Other', false, 'reader')];
        $this->google->failure = new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        $this->postJson('/api/study/google-calendar/preview', $body)->assertStatus(503)->assertExactJson([
            'error' => ['code' => 'google_calendar_provider_unavailable', 'message' => 'Google Calendar is temporarily unavailable.'],
        ]);
    }

    private function body(): array
    {
        return ['calendarIds' => ['work'], 'titleMatchTerms' => ['lesson']];
    }

    private function connection(User $user, string $account = 'account'): void
    {
        GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id, 'provider_account_id' => $account, 'access_token' => 'access',
            'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'scopes' => [],
            'settings' => [], 'connected_at' => now(),
        ]);
    }

    private function createSession(User $user, string $sourceKey): void
    {
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id, 'client_session_id' => (string) Str::ulid(), 'category' => 'conversation',
            'activity' => 'conversation', 'source' => 'calendar', 'origin' => 'google_calendar',
            'source_key' => $sourceKey, 'started_at' => now()->subHour(), 'ended_at' => now(), 'duration_ms' => 3_600_000,
        ]);
    }

    private function event(string $id, ?string $title, string $start, string $end, string $status = 'confirmed', ?string $recurring = null): GoogleCalendarEvent
    {
        return new GoogleCalendarEvent($id, $status, $title, new GoogleCalendarEventTime(null, $start, null),
            new GoogleCalendarEventTime(null, $end, null), $recurring === null ? null : new GoogleCalendarEventTime(null, $start, null), null, $recurring);
    }
}

final class FakeCalendarPreviewTransport implements GoogleCalendarReadTransport
{
    public array $calendars = [];

    public array $eventPages = [];

    public array $eventCalls = [];

    public ?GoogleCalendarProviderException $failure = null;

    public function refresh(string $refreshToken): GoogleCalendarTokenGrant
    {
        return new GoogleCalendarTokenGrant('access', 3600, null);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        return new GoogleCalendarPage($this->calendars, null, null);
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->eventCalls[] = [$accessToken, $calendarId, [$query->timeMin, $query->timeMax, $query->syncToken, $query->pageToken, $query->maxResults]];

        return array_shift($this->eventPages) ?? new GoogleCalendarPage([], null, null);
    }
}
