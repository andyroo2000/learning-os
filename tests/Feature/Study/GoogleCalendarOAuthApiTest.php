<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ConnectGoogleCalendarAction;
use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Http\Controllers\Web\Calendar\BeginGoogleCalendarOAuthController;
use App\Http\Controllers\Web\Calendar\CompleteGoogleCalendarOAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

    public function test_callback_persists_a_verified_grant_and_exposes_safe_status(): void
    {
        Carbon::setTestNow('2026-08-15T20:00:00Z');
        $user = User::factory()->create();
        $this->authorize($user);

        $this->get('/api/study/google-calendar/callback?code=secret-code')
            ->assertRedirect('https://convo-lab.test/app/study/time?calendarConnection=connected');

        $this->getJson('/api/study/google-calendar')->assertExactJson([
            'connected' => true,
            'accountEmail' => 'andrew@example.com',
            'scopes' => [FakeGoogleCalendarOAuthClient::SCOPE],
            'settings' => ['calendarIds' => ['primary'], 'syncEnabled' => true],
            'connectedAt' => '2026-08-15T20:00:00Z',
            'lastSyncedAt' => null,
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

        $this->google->grant = $this->grant(accessToken: 'replacement', refreshToken: null);
        $this->authorize($user);
        $this->get('/api/study/google-calendar/callback')->assertRedirectContains('calendarConnection=connected');

        $connection = GoogleCalendarConnection::query()->firstOrFail();
        $this->assertSame('replacement', $connection->access_token);
        $this->assertSame('refresh-secret', $connection->refresh_token);
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
        $this->get('/api/study/google-calendar/callback?error=access_denied&error_description=secret')
            ->assertRedirect('https://convo-lab.test/app/study/time?calendarConnection=error&reason=access_denied');

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

    public function grant(): GoogleCalendarOAuthGrant
    {
        throw_if($this->failure !== null, $this->failure);

        return $this->grant ?? new GoogleCalendarOAuthGrant('google-subject', 'andrew@example.com', 'access-secret', 'refresh-secret', 3600, [self::SCOPE]);
    }
}
