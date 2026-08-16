<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\GetGoogleCalendarAccessTokenAction;
use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoogleCalendarAccessTokenActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarTransport $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google = new FakeCalendarTransport;
        $this->app->instance(GoogleCalendarReadTransport::class, $this->google);
    }

    public function test_fresh_token_avoids_refresh_and_expiry_skew_refreshes(): void
    {
        $user = User::factory()->create();
        $fresh = $this->connection($user, ['token_expires_at' => now()->addMinutes(6)]);
        $this->assertSame(['old-access', 0], [$this->action()->handle($user->id), $this->google->calls]);

        $fresh->forceFill(['token_expires_at' => now()->addMinutes(5)])->save();
        $this->google->grant = new GoogleCalendarTokenGrant('new-access', 3600, null);
        $this->assertSame(['new-access', 1, 'old-refresh'], [$this->action()->handle($user->id), $this->google->calls, $fresh->fresh()->refresh_token]);
    }

    public function test_refresh_rotation_is_encrypted_and_persisted(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $this->google->grant = new GoogleCalendarTokenGrant('new-access', 7200, 'new-refresh');

        $this->action()->handle($user->id);

        $updated = $connection->fresh();
        $this->assertSame(['new-access', 'new-refresh'], [$updated->access_token, $updated->refresh_token]);
    }

    public function test_missing_refresh_and_foreign_connection_are_rejected_without_network(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($owner, ['refresh_token' => null]);

        try {
            $this->action()->handle($owner->id);
            $this->fail('Expected reconnect requirement.');
        } catch (GoogleCalendarProviderException $exception) {
            $this->assertSame('google_calendar_reconnect_required', $exception->reason());
        }
        $this->expectException(ModelNotFoundException::class);
        try {
            $this->action()->handle($other->id);
        } finally {
            $this->assertSame(0, $this->google->calls);
        }
    }

    public function test_provider_refresh_runs_without_a_transaction_and_cannot_overwrite_a_reconnect(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $transactionLevel = DB::transactionLevel();
        $this->google->grant = new GoogleCalendarTokenGrant('stale-grant', 3600, 'stale-refresh');
        $this->google->onRefresh = function () use ($connection, $transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $connection->forceFill([
                'provider_account_id' => 'replacement-account',
                'access_token' => 'replacement-access',
                'refresh_token' => 'replacement-refresh',
                'token_expires_at' => now()->addHour(),
            ])->save();
        };

        $this->assertSame('replacement-access', $this->action()->handle($user->id));
        $this->assertSame(
            [
                'provider_account_id' => 'replacement-account',
                'access_token' => 'replacement-access',
                'refresh_token' => 'replacement-refresh',
            ],
            $connection->fresh()->only(['provider_account_id', 'access_token', 'refresh_token']),
        );
    }

    private function action(): GetGoogleCalendarAccessTokenAction
    {
        return app(GetGoogleCalendarAccessTokenAction::class);
    }

    private function connection(User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate(array_merge([
            'user_id' => $user->id, 'provider_account_id' => 'subject-'.$user->id,
            'access_token' => 'old-access', 'refresh_token' => 'old-refresh',
            'token_expires_at' => now()->subMinute(), 'scopes' => [], 'settings' => [],
            'connected_at' => now(),
        ], $overrides));
    }
}

final class FakeCalendarTransport implements GoogleCalendarReadTransport
{
    public int $calls = 0;

    public ?GoogleCalendarTokenGrant $grant = null;

    public ?\Closure $onRefresh = null;

    public function refresh(string $refreshToken): GoogleCalendarTokenGrant
    {
        $this->calls++;
        ($this->onRefresh ?? static fn () => null)();

        return $this->grant ?? new GoogleCalendarTokenGrant('new-access', 3600, null);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        throw new \LogicException;
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        throw new \LogicException;
    }
}
