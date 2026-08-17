<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ConnectGoogleCalendarAction;
use App\Domain\Calendar\Actions\PruneExpiredGoogleCalendarConnectIntentsAction;
use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use App\Domain\Calendar\Models\GoogleCalendarConnectIntent;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Http\Controllers\Web\Calendar\BeginGoogleCalendarOAuthController;
use App\Http\Controllers\Web\Calendar\CompleteGoogleCalendarOAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class GoogleCalendarOAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleCalendarOAuthClient $google;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.convolab.client_url', 'https://convo-lab.test');
        $this->google = new FakeGoogleCalendarOAuthClient;
        $this->app->instance(GoogleCalendarOAuthClient::class, $this->google);
    }

    public function test_begin_requires_authentication_and_binds_the_user_session(): void
    {
        $this->get('/api/study/google-calendar/connect')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get('/api/study/google-calendar/connect')
            ->assertRedirect('https://accounts.google.test/calendar');

        $this->assertSame(1, $this->google->redirectCalls);

        $begin = Route::getRoutes()->getByAction(BeginGoogleCalendarOAuthController::class);
        $callback = Route::getRoutes()->getByAction(CompleteGoogleCalendarOAuthController::class);
        $this->assertSame(['web', 'auth:web', 'throttle:study-compatibility-network', 'throttle:study-compatibility-read', 'throttle:google-calendar-oauth-begin'], $begin?->gatherMiddleware());
        $this->assertSame(['web', 'throttle:study-compatibility-network', 'throttle:study-compatibility-read', 'throttle:google-calendar-oauth-callback'], $callback?->gatherMiddleware());
    }

    public function test_authenticated_api_creates_hashed_ten_minute_ios_intent(): void
    {
        Carbon::setTestNow('2026-08-15T20:00:00Z');
        $this->postJson('/api/study/google-calendar/connect', ['completionTarget' => 'ios'])
            ->assertUnauthorized();

        $otherUser = User::factory()->create();
        (new GoogleCalendarConnectIntent)->forceFill([
            'state_hash' => hash('sha256', 'other-user-expired-state'),
            'user_id' => $otherUser->id,
            'completion_target' => 'ios',
            'expires_at' => now()->subMinute(),
        ])->save();
        $user = User::factory()->create();
        $url = $this->actingAs($user)->postJson('/api/study/google-calendar/connect', [
            'completionTarget' => 'ios',
        ])->assertOk()->json('authorizationUrl');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'] ?? null;

        $this->assertIsString($state);
        $this->assertSame(64, strlen($state));
        $intent = GoogleCalendarConnectIntent::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(hash('sha256', $state), $intent->getKey());
        $this->assertSame('ios', $intent->completion_target);
        $this->assertSame('2026-08-15T20:10:00+00:00', $intent->expires_at->toIso8601String());
        $this->assertDatabaseMissing('google_calendar_connect_intents', ['state_hash' => $state]);
        $this->assertDatabaseHas('google_calendar_connect_intents', ['user_id' => $otherUser->id]);
    }

    public function test_ios_intent_is_claimed_once_before_stateless_exchange(): void
    {
        $user = User::factory()->create();
        $url = $this->actingAs($user)->postJson('/api/study/google-calendar/connect', [
            'completionTarget' => 'ios',
        ])->json('authorizationUrl');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->get('/api/study/google-calendar/callback?code=secret&state='.$query['state'])
            ->assertRedirect('convolab://study-time?calendarConnection=connected');
        $this->assertDatabaseCount('google_calendar_connect_intents', 0);
        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $user->id]);

        $this->get('/api/study/google-calendar/callback?code=replay&state='.$query['state'])
            ->assertRedirect('https://convo-lab.test/app/settings/integrations?calendarConnection=error&reason=invalid_state');
    }

    public function test_expired_intent_is_consumed_and_untrusted_targets_use_safe_web_error(): void
    {
        Carbon::setTestNow('2026-08-15T20:00:00Z');
        $user = User::factory()->create();
        $url = $this->actingAs($user)->postJson('/api/study/google-calendar/connect', [
            'completionTarget' => 'ios',
        ])->json('authorizationUrl');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        Carbon::setTestNow('2026-08-15T20:11:00Z');

        $this->get('/api/study/google-calendar/callback?code=secret&state='.$query['state'])
            ->assertRedirect('https://convo-lab.test/app/settings/integrations?calendarConnection=error&reason=invalid_state');
        $this->assertDatabaseCount('google_calendar_connect_intents', 0);
        $this->assertDatabaseCount('google_calendar_connections', 0);

        $this->get('/api/study/google-calendar/callback?state='.str_repeat('a', 64))
            ->assertRedirect('https://convo-lab.test/app/settings/integrations?calendarConnection=error&reason=invalid_state');
    }

    public function test_ios_denial_uses_fixed_deep_link_without_provider_text(): void
    {
        $user = User::factory()->create();
        $url = $this->actingAs($user)->postJson('/api/study/google-calendar/connect', [
            'completionTarget' => 'ios',
        ])->json('authorizationUrl');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $response = $this->get('/api/study/google-calendar/callback?error=provider_secret&error_description=must-not-leak&state='.$query['state'])
            ->assertRedirect('convolab://study-time?calendarConnection=error&reason=oauth_failed');
        $this->assertStringNotContainsString('must-not-leak', $response->headers->get('Location'));
    }

    public function test_connect_intent_rejects_arbitrary_targets_and_web_completion_is_fixed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/study/google-calendar/connect', [
            'completionTarget' => 'https://attacker.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('completionTarget');

        $url = $this->postJson('/api/study/google-calendar/connect', [
            'completionTarget' => 'web',
        ])->json('authorizationUrl');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->get('/api/study/google-calendar/callback?code=secret&state='.$query['state'])
            ->assertRedirect('https://convo-lab.test/app/settings/integrations?calendarConnection=connected');
    }

    public function test_abandoned_intents_are_pruned_by_an_hourly_single_server_task(): void
    {
        $user = User::factory()->create();
        foreach ([now()->subMinute(), now()->addMinute()] as $index => $expiry) {
            (new GoogleCalendarConnectIntent)->forceFill([
                'state_hash' => hash('sha256', 'scheduled-prune-'.$index),
                'user_id' => $user->id, 'completion_target' => 'ios', 'expires_at' => $expiry,
            ])->save();
        }

        $event = collect(Schedule::events())->first(fn ($event): bool => $event->description === 'calendar:prune-connect-intents');
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);

        app(PruneExpiredGoogleCalendarConnectIntentsAction::class)->handle();
        $this->assertDatabaseCount('google_calendar_connect_intents', 1);
    }

    public function test_callback_persists_a_verified_grant_and_exposes_safe_status(): void
    {
        Carbon::setTestNow('2026-08-15T20:00:00Z');
        $user = User::factory()->create();
        $this->authorize($user);

        $this->get('/api/study/google-calendar/callback?code=secret-code')
            ->assertRedirect('https://convo-lab.test/app/settings/integrations?calendarConnection=connected');

        $this->getJson('/api/study/google-calendar')->assertExactJson([
            'connected' => true,
            'accountEmail' => 'andrew@example.com',
            'scopes' => [FakeGoogleCalendarOAuthClient::SCOPE],
            'settings' => null,
            'connectedAt' => '2026-08-15T20:00:00Z',
            'lastSyncedAt' => null,
            'sync' => ['status' => 'idle', 'errorCode' => null, 'statusAt' => '2026-08-15T20:00:00Z'],
        ]);
        $raw = DB::table('google_calendar_connections')->first();
        $this->assertNotSame('access-secret', $raw->access_token);
        $this->assertNotSame('refresh-secret', $raw->refresh_token);
    }

    public function test_reconnect_preserves_an_omitted_refresh_token_for_the_same_account(): void
    {
        $user = User::factory()->create();
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback');
        $connection = GoogleCalendarConnection::query()->firstOrFail();
        $connection->forceFill([
            'settings' => ['calendarIds' => ['private'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true],
            'sync_cursors' => ['private' => 'cursor'], 'last_synced_at' => Carbon::parse('2026-08-14T20:00:00Z'),
        ])->save();

        $this->google->grant = $this->grant(accessToken: 'replacement', refreshToken: null);
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback')->assertRedirectContains('calendarConnection=connected');

        $connection->refresh();
        $this->assertSame('replacement', $connection->access_token);
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertSame(['private' => 'cursor'], $connection->sync_cursors);
        $this->assertSame('2026-08-14T20:00:00+00:00', $connection->last_synced_at?->toIso8601String());
        $this->assertSame(['private'], $connection->settings['calendarIds']);
    }

    public function test_connecting_a_different_account_clears_prior_calendar_settings(): void
    {
        $user = User::factory()->create();
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback');
        GoogleCalendarConnection::query()->firstOrFail()->forceFill([
            'settings' => ['calendarIds' => ['private'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true],
            'sync_cursors' => ['private' => 'cursor'], 'last_synced_at' => now(),
        ])->save();
        $this->google->grant = new GoogleCalendarOAuthGrant('different-subject', 'other@example.com', 'new-access', 'new-refresh', 3600, [FakeGoogleCalendarOAuthClient::SCOPE]);
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback');

        $connection = GoogleCalendarConnection::query()->firstOrFail();
        $this->assertSame([], $connection->settings);
        $this->assertNull($connection->sync_cursors);
        $this->assertNull($connection->last_synced_at);
    }

    public function test_callback_rejects_cross_user_account_conflicts(): void
    {
        $first = User::factory()->create();
        $this->authorize($first);
        $this->get('/api/study/google-calendar/callback');

        $second = User::factory()->create();
        $this->authorize($second);
        $this->get('/api/study/google-calendar/callback')
            ->assertRedirectContains('calendarConnection=error&reason=account_conflict');

        $this->assertDatabaseCount('google_calendar_connections', 1);
    }

    public function test_same_user_same_account_unique_race_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback');
        $action = app(ConnectGoogleCalendarAction::class);

        $this->assertTrue($action->sameUserWonRace($user->id, 'google-subject'));
        $this->assertFalse($action->sameUserWonRace($user->id, 'different-subject'));
    }

    public function test_callback_handles_denial_invalid_binding_and_provider_failures_safely(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get('/api/study/google-calendar/callback')
            ->assertRedirectContains('reason=invalid_state');

        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback?error=access_denied&error_description=secret&state=oauth-state')
            ->assertRedirect('https://convo-lab.test/app/settings/integrations?calendarConnection=error&reason=access_denied');

        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback?error=access_denied&state=forged')
            ->assertRedirectContains('reason=invalid_state');

        $this->google->failure = new GoogleCalendarOAuthException('missing_token');
        $this->authorize($user);
        $response = $this->get('/api/study/google-calendar/callback?code=must-not-leak')
            ->assertRedirectContains('reason=missing_token');
        $this->assertStringNotContainsString('must-not-leak', $response->headers->get('Location'));

        $this->google->failure = new InvalidStateException;
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback')->assertRedirectContains('reason=invalid_state');
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_new_connection_requires_an_offline_refresh_token(): void
    {
        $user = User::factory()->create();
        $this->google->grant = $this->grant(refreshToken: null);
        $this->authorize($user);

        $this->get('/api/study/google-calendar/callback')
            ->assertRedirectContains('reason=missing_refresh_token');
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    private function authorize(User $user): void
    {
        $this->actingAs($user, 'web')->get('/api/study/google-calendar/connect')->assertRedirect();
        session()->put('state', 'oauth-state');
    }

    private function grant(string $accessToken = 'access-secret', ?string $refreshToken = 'refresh-secret'): GoogleCalendarOAuthGrant
    {
        return new GoogleCalendarOAuthGrant('google-subject', 'andrew@example.com', $accessToken, $refreshToken, 3600, [FakeGoogleCalendarOAuthClient::SCOPE]);
    }
}

final class FakeGoogleCalendarOAuthClient implements GoogleCalendarOAuthClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';

    public int $redirectCalls = 0;

    public ?GoogleCalendarOAuthGrant $grant = null;

    public ?\Throwable $failure = null;

    public function redirect(): RedirectResponse
    {
        $this->redirectCalls++;

        return new RedirectResponse('https://accounts.google.test/calendar');
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.test/calendar?state='.$state;
    }

    public function grant(): GoogleCalendarOAuthGrant
    {
        throw_if($this->failure !== null, $this->failure);

        return $this->grant ?? new GoogleCalendarOAuthGrant('google-subject', 'andrew@example.com', 'access-secret', 'refresh-secret', 3600, [self::SCOPE]);
    }

    public function statelessGrant(): GoogleCalendarOAuthGrant
    {
        return $this->grant();
    }
}
