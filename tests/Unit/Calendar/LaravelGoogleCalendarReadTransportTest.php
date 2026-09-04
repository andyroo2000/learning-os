<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Services\LaravelGoogleCalendarReadTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaravelGoogleCalendarReadTransportTest extends TestCase
{
    public function test_refresh_uses_fixed_endpoint_and_maps_rotation(): void
    {
        config(['services.google_calendar.client_id' => 'client', 'services.google_calendar.client_secret' => 'secret']);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access', 'expires_in' => 3600, 'refresh_token' => 'rotated',
        ])]);

        $grant = $this->transport()->refresh('refresh');
        $this->assertSame('rotated', $grant->refreshToken);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->isForm() && $request['client_id'] === 'client' && $request['client_secret'] === 'secret'
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh');
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRefreshPayloads')]
    public function test_refresh_rejects_invalid_provider_token_payloads(array $payload): void
    {
        Http::fake(['*' => Http::response($payload)]);

        $this->assertProviderFailure(fn () => $this->transport()->refresh('refresh'));
    }

    public function test_invalid_grant_is_sanitized_without_secret_leakage(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'secret provider detail'], 400)]);

        try {
            $this->transport()->refresh('sensitive-refresh');
            $this->fail('Expected reconnect requirement.');
        } catch (GoogleCalendarProviderException $exception) {
            $this->assertSame(['google_calendar_reconnect_required', 'Google Calendar could not be reached safely.'], [$exception->reason(), $exception->getMessage()]);
        }
    }

    public function test_other_provider_failures_are_sanitized(): void
    {
        Http::fakeSequence()->push([], 401)->push([], 410)->push([], 503);
        foreach ([401 => 'google_calendar_reconnect_required', 410 => 'google_calendar_sync_token_expired', 503 => 'google_calendar_provider_unavailable'] as $status => $reason) {
            try {
                $this->transport()->calendars('access');
                $this->fail('Expected provider exception.');
            } catch (GoogleCalendarProviderException $exception) {
                $this->assertSame($reason, $exception->reason());
            }
        }
    }

    public function test_calendar_list_normalizes_page_and_exact_authenticated_request(): void
    {
        Http::fake(['*' => Http::response(['items' => [[
            'id' => 'primary', 'summary' => 'Andrew', 'primary' => true, 'accessRole' => 'owner',
        ]], 'nextPageToken' => 'next', 'nextSyncToken' => 'sync'])]);

        $page = $this->transport()->calendars('access', 'page', 25);
        $this->assertSame(['id' => 'primary', 'summary' => 'Andrew', 'primary' => true, 'accessRole' => 'owner'], (array) $page->items[0]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=25&pageToken=page'
            && $request->hasHeader('Authorization', 'Bearer access'));
    }

    public function test_calendar_primary_defaults_false_and_rejects_non_boolean_values(): void
    {
        Http::fakeSequence()
            ->push(['items' => [['id' => 'secondary', 'summary' => 'Team', 'accessRole' => 'reader']]])
            ->push(['items' => [['id' => 'invalid', 'summary' => 'Team', 'primary' => 1, 'accessRole' => 'reader']]]);

        $this->assertFalse($this->transport()->calendars('access')->items[0]->primary);
        $this->assertProviderFailure(fn () => $this->transport()->calendars('access'));
    }

    public function test_events_encode_calendar_id_and_normalize_timed_all_day_and_cancelled_fields(): void
    {
        Http::fake(['*' => Http::response(['items' => [
            ['id' => 'timed', 'status' => 'confirmed', 'summary' => 'Lesson',
                'start' => ['dateTime' => '2026-08-15T10:00:00-04:00'], 'end' => ['dateTime' => '2026-08-15T11:00:00-04:00'],
                'updated' => '2026-08-15T15:00:00Z'],
            ['id' => 'all-day', 'status' => 'confirmed', 'start' => ['date' => '2026-08-16'], 'end' => ['date' => '2026-08-17']],
            ['id' => 'cancelled', 'status' => 'cancelled', 'recurringEventId' => 'series', 'originalStartTime' => ['dateTime' => '2026-08-18T10:00:00-04:00']],
        ]])]);

        $page = $this->transport()->events('token', 'team/calendar@example.com', new GoogleCalendarEventQuery(
            '2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', pageToken: 'p', maxResults: 50,
        ));
        $this->assertSame(['2026-08-15T10:00:00-04:00', '2026-08-16', null, '2026-08-18T10:00:00-04:00', 'series'], [$page->items[0]->start->dateTime, $page->items[1]->start->date, $page->items[2]->start, $page->items[2]->originalStartTime->dateTime, $page->items[2]->recurringEventId]);
        Http::assertSent(function (Request $request): bool {
            $expected = 'https://www.googleapis.com/calendar/v3/calendars/team%2Fcalendar%40example.com/events';
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), $expected.'?') && $query === [
                'maxResults' => '50', 'pageToken' => 'p', 'timeMin' => '2026-08-01T00:00:00Z',
                'timeMax' => '2026-09-01T00:00:00Z', 'singleEvents' => 'true', 'showDeleted' => 'true',
            ] && $request->hasHeader('Authorization', 'Bearer token');
        });

        $this->transport()->events('token', 'primary', new GoogleCalendarEventQuery(timeMin: '2026-08-01T00:00:00Z'));
        $this->transport()->events('token', 'primary', new GoogleCalendarEventQuery(syncToken: 'sync'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'timeMin=') && ! str_contains($request->url(), 'timeMax='));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'syncToken=sync') && ! str_contains($request->url(), 'timeMin='));
    }

    #[DataProvider('invalidEventQueries')]
    public function test_event_queries_reject_invalid_full_and_incremental_windows_before_http(
        string $calendarId,
        GoogleCalendarEventQuery $query,
    ): void {
        Http::fake();

        $this->assertProviderFailure(
            fn () => $this->transport()->events('token', $calendarId, $query),
            GoogleCalendarProviderException::INVALID_REQUEST,
        );

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $start */
    #[DataProvider('invalidEventTimes')]
    public function test_events_reject_invalid_provider_time_shapes(array $start): void
    {
        Http::fake(['*' => Http::response(['items' => [[
            'id' => 'event', 'status' => 'confirmed', 'start' => $start,
        ]]])]);

        $this->assertProviderFailure(fn () => $this->transport()->events(
            'token',
            'primary',
            new GoogleCalendarEventQuery(timeMin: '2026-08-01T00:00:00Z'),
        ));
    }

    public function test_malformed_responses_and_unbounded_page_tokens_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['items' => [['id' => 'only']]])]);
        try {
            $this->transport()->calendars('token');
            $this->fail('Expected invalid response.');
        } catch (GoogleCalendarProviderException $exception) {
            $this->assertSame('google_calendar_invalid_response', $exception->reason());
        }

        foreach ([
            fn () => $this->transport()->calendars('token', str_repeat('x', 4097)),
            fn () => $this->transport()->events('token', 'primary', new GoogleCalendarEventQuery(syncToken: str_repeat('x', 4097))),
        ] as $request) {
            try {
                $request();
                $this->fail('Expected bounded token rejection.');
            } catch (GoogleCalendarProviderException $exception) {
                $this->assertSame('google_calendar_invalid_request', $exception->reason());
            }
        }
    }

    private function transport(): LaravelGoogleCalendarReadTransport
    {
        return app(LaravelGoogleCalendarReadTransport::class);
    }

    private function assertProviderFailure(callable $operation, string $reason = GoogleCalendarProviderException::INVALID_RESPONSE): void
    {
        try {
            $operation();
            $this->fail('Expected provider exception.');
        } catch (GoogleCalendarProviderException $exception) {
            $this->assertSame($reason, $exception->reason());
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRefreshPayloads(): array
    {
        return [
            'missing access token' => [['expires_in' => 3600]],
            'blank access token' => [['access_token' => '', 'expires_in' => 3600]],
            'string expiry' => [['access_token' => 'access', 'expires_in' => '3600']],
            'expiry below minimum' => [['access_token' => 'access', 'expires_in' => 59]],
            'expiry above maximum' => [['access_token' => 'access', 'expires_in' => 86401]],
            'explicit null rotated token' => [['access_token' => 'access', 'expires_in' => 3600, 'refresh_token' => null]],
        ];
    }

    /** @return array<string, array{string, GoogleCalendarEventQuery}> */
    public static function invalidEventQueries(): array
    {
        return [
            'blank calendar id' => ['', new GoogleCalendarEventQuery(timeMin: '2026-08-01T00:00:00Z')],
            'missing full-sync lower bound' => ['primary', new GoogleCalendarEventQuery],
            'malformed lower bound' => ['primary', new GoogleCalendarEventQuery(timeMin: 'tomorrow')],
            'malformed upper bound' => ['primary', new GoogleCalendarEventQuery(timeMin: '2026-08-01T00:00:00Z', timeMax: 'tomorrow')],
            'incremental query with time bound' => ['primary', new GoogleCalendarEventQuery(timeMin: '2026-08-01T00:00:00Z', syncToken: 'sync')],
            'blank sync token' => ['primary', new GoogleCalendarEventQuery(syncToken: '')],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidEventTimes(): array
    {
        return [
            'missing date and date-time' => [[]],
            'date and date-time together' => [['date' => '2026-08-16', 'dateTime' => '2026-08-16T10:00:00Z']],
            'impossible date' => [['date' => '2026-02-30']],
            'date-time without timezone' => [['dateTime' => '2026-08-16 10:00:00']],
        ];
    }
}
