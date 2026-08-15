<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendar;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarSettingsDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarDiscoveryTransport $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google = new FakeCalendarDiscoveryTransport;
        $this->app->instance(GoogleCalendarReadTransport::class, $this->google);
    }

    public function test_new_endpoints_require_authentication_and_disconnected_errors_are_safe(): void
    {
        $this->getJson('/api/study/google-calendar/calendars')->assertUnauthorized();
        $this->putJson('/api/study/google-calendar/settings', $this->settings())->assertUnauthorized();
        $error = ['error' => ['code' => 'not_connected', 'message' => 'Connect Google Calendar before continuing.']];
        $this->actingAs(User::factory()->create())->getJson('/api/study/google-calendar/calendars')->assertStatus(409)->assertExactJson($error);
        $this->putJson('/api/study/google-calendar/settings', $this->settings())->assertStatus(409)->assertExactJson($error);
    }

    public function test_settings_are_canonical_and_reset_only_the_owners_provider_state(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($owner);
        $otherConnection = $this->connection($other, ['provider_account_id' => 'other']);
        $body = ['calendarIds' => ["\u{00A0}primary\u{3000}", 'primary', 'WORK'], 'titleMatchTerms' => ["\u{3000}iTalki\u{00A0}", 'ITALKI', '会話'], 'syncEnabled' => false];

        $this->withoutMiddleware(TrimStrings::class)->actingAs($owner)->putJson('/api/study/google-calendar/settings', $body)
            ->assertOk()->assertExactJson(['calendarIds' => ['primary', 'WORK'], 'titleMatchTerms' => ['iTalki', '会話'], 'syncEnabled' => false]);

        $fresh = $connection->fresh();
        $this->assertSame(['primary', 'WORK'], $fresh->settings['calendarIds']);
        $this->assertNull($fresh->sync_cursors);
        $this->assertNull($fresh->last_synced_at);
        $this->assertSame(['primary' => 'cursor'], $otherConnection->fresh()->sync_cursors);
        $fresh->forceFill(['sync_cursors' => ['WORK' => 'good'], 'last_synced_at' => now()])->save();
        $this->putJson('/api/study/google-calendar/settings', $body)->assertOk();
        $this->assertSame(['WORK' => 'good'], $fresh->fresh()->sync_cursors);
    }

    #[DataProvider('invalidSettingsProvider')]
    public function test_settings_validation_rejects_invalid_shapes(array $overrides, string $error): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $this->actingAs($user)->putJson('/api/study/google-calendar/settings', array_replace($this->settings(), $overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
    }

    public static function invalidSettingsProvider(): array
    {
        return [
            'empty ids' => [['calendarIds' => []], 'calendarIds'],
            'many ids' => [['calendarIds' => array_fill(0, 26, 'id')], 'calendarIds'],
            'long id' => [['calendarIds' => [str_repeat('a', 1025)]], 'calendarIds.0'],
            'blank id' => [['calendarIds' => ['  ']], 'calendarIds.0'],
            'non string id' => [['calendarIds' => [4]], 'calendarIds.0'],
            'empty terms' => [['titleMatchTerms' => []], 'titleMatchTerms'],
            'many terms' => [['titleMatchTerms' => array_fill(0, 51, 'term')], 'titleMatchTerms'],
            'long unicode term' => [['titleMatchTerms' => [str_repeat('会', 101)]], 'titleMatchTerms.0'],
            'missing boolean' => [['syncEnabled' => null], 'syncEnabled'],
            'integer boolean' => [['syncEnabled' => 1], 'syncEnabled'],
        ];
    }

    public function test_discovery_refreshes_tokens_filters_dedupes_sorts_and_hides_page_tokens(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, ['token_expires_at' => now()->subMinute()]);
        $this->google->refreshGrant = new GoogleCalendarTokenGrant('refreshed', 3600, null);
        $this->google->pages = [
            new GoogleCalendarPage([
                new GoogleCalendar('busy', 'Busy only', false, 'freeBusyReader'),
                new GoogleCalendar('unknown', 'Unknown', false, 'writerWithoutPrivateAccess'),
                new GoogleCalendar('z', 'Zeta', false, 'reader'),
                new GoogleCalendar('p', 'Middle', true, 'owner'),
                new GoogleCalendar('a', 'alpha', false, 'writer'),
            ], 'opaque-provider-page', null),
            new GoogleCalendarPage([
                new GoogleCalendar('a', 'duplicate', false, 'writer'),
                new GoogleCalendar('b', 'Beta', false, 'writer'),
            ], null, 'provider-sync-secret'),
        ];

        $this->actingAs($user)->getJson('/api/study/google-calendar/calendars')->assertOk()->assertExactJson([
            'calendars' => [
                ['id' => 'p', 'name' => 'Middle', 'primary' => true],
                ['id' => 'a', 'name' => 'alpha', 'primary' => false],
                ['id' => 'b', 'name' => 'Beta', 'primary' => false],
                ['id' => 'z', 'name' => 'Zeta', 'primary' => false],
            ],
            'truncated' => false,
        ]);
        $this->assertSame([['refreshed', null, 250], ['refreshed', 'opaque-provider-page', 250]], $this->google->calendarCalls);
        $this->assertSame('refreshed', $connection->fresh()->access_token);
    }

    public function test_discovery_stops_after_four_provider_pages_and_marks_truncation(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $this->google->pages = array_fill(0, 4, new GoogleCalendarPage([], 'next', null));

        $this->actingAs($user)->getJson('/api/study/google-calendar/calendars')
            ->assertExactJson(['calendars' => [], 'truncated' => true]);
        $this->assertCount(4, $this->google->calendarCalls);
    }

    #[DataProvider('providerErrorProvider')]
    public function test_provider_failures_have_fixed_safe_errors(string $reason, int $status, string $message): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $this->google->failure = new GoogleCalendarProviderException($reason);

        $this->actingAs($user)->getJson('/api/study/google-calendar/calendars')->assertStatus($status)->assertExactJson([
            'error' => ['code' => $reason, 'message' => $message],
        ]);
    }

    public static function providerErrorProvider(): array
    {
        return [
            'reconnect' => [GoogleCalendarProviderException::RECONNECT_REQUIRED, 409, 'Reconnect Google Calendar before continuing.'],
            'unavailable' => [GoogleCalendarProviderException::UNAVAILABLE, 503, 'Google Calendar is temporarily unavailable.'],
            'invalid' => [GoogleCalendarProviderException::INVALID_RESPONSE, 502, 'Google Calendar returned an invalid response.'],
            'bad request' => [GoogleCalendarProviderException::INVALID_REQUEST, 500, 'Google Calendar could not be queried safely.'],
            'cursor expired' => [GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED, 409, 'Google Calendar changed; refresh its calendars and try again.'],
        ];
    }

    private function settings(): array
    {
        return ['calendarIds' => ['primary'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true];
    }

    private function connection(User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate(array_merge([
            'user_id' => $user->id, 'provider_account_id' => 'subject-'.$user->id,
            'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'scopes' => [], 'settings' => [], 'sync_cursors' => ['primary' => 'cursor'],
            'connected_at' => now(), 'last_synced_at' => now(),
        ], $overrides));
    }
}

final class FakeCalendarDiscoveryTransport implements GoogleCalendarReadTransport
{
    public array $pages = [];

    public array $calendarCalls = [];

    public ?GoogleCalendarTokenGrant $refreshGrant = null;

    public ?GoogleCalendarProviderException $failure = null;

    public function refresh(string $refreshToken): GoogleCalendarTokenGrant
    {
        return $this->refreshGrant ?? new GoogleCalendarTokenGrant('access', 3600, null);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->calendarCalls[] = [$accessToken, $pageToken, $maxResults];

        return array_shift($this->pages) ?? new GoogleCalendarPage([], null, null);
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        throw new \LogicException;
    }
}
