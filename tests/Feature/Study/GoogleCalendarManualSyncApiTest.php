<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Support\GoogleCalendarConnectionRateLimiter;
use App\Domain\Calendar\Support\GoogleCalendarSyncRun;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleCalendarManualSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-16T15:16:17Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sync_route_requires_authentication(): void
    {
        $this->postJson('/api/study/google-calendar/sync')->assertUnauthorized();
    }

    public function test_no_body_manual_sync_queues_while_disabled_and_returns_the_full_connection_shape(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'settings' => $this->settings(false),
            'last_synced_at' => now()->subDay(),
            'sync_status' => GoogleCalendarSyncStatus::Failed,
            'sync_error_code' => 'provider_unavailable',
            'sync_status_at' => now()->subHour(),
        ]);

        $this->actingAs($user)->call('POST', '/api/study/google-calendar/sync', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], '')
            ->assertStatus(202)
            ->assertExactJson([
                'connected' => true,
                'accountEmail' => $user->email,
                'scopes' => ['calendar.readonly'],
                'settings' => $this->settings(false),
                'connectedAt' => '2026-08-16T15:16:17Z',
                'lastSyncedAt' => '2026-08-15T15:16:17Z',
                'sync' => ['status' => 'queued', 'errorCode' => null, 'statusAt' => '2026-08-16T15:16:17Z'],
            ]);

        Queue::assertPushed(SyncGoogleCalendarConnection::class, function ($job) use ($connection, $user): bool {
            return $job->connectionId === $connection->id && $job->userId === $user->id && ! $job->requireEnabled;
        });
        $this->assertNotNull($connection->fresh()->sync_run_id);
        $this->assertSame('2026-08-15T15:16:17Z', $connection->fresh()->last_synced_at->toIso8601ZuluString());
    }

    public function test_disconnected_and_invalid_persisted_settings_have_safe_errors(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $notConnected = ['error' => ['code' => 'not_connected', 'message' => 'Connect Google Calendar before continuing.']];
        $settingsRequired = ['error' => ['code' => 'settings_required', 'message' => 'Choose Google Calendar sync settings before syncing.']];

        $this->actingAs($user)->postJson('/api/study/google-calendar/sync')
            ->assertStatus(409)->assertExactJson($notConnected);

        $connection = $this->connection($user, ['settings' => []]);
        $this->postJson('/api/study/google-calendar/sync')->assertStatus(422)->assertExactJson($settingsRequired);
        $connection->forceFill(['settings' => ['calendarIds' => ['work'], 'syncEnabled' => true]])->save();
        $this->postJson('/api/study/google-calendar/sync')->assertStatus(422)->assertExactJson($settingsRequired);
        Queue::assertNothingPushed();
    }

    public function test_fresh_active_requests_are_idempotent_and_do_not_expose_or_replace_the_run(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = $this->connection($user);

        foreach ([GoogleCalendarSyncStatus::Queued, GoogleCalendarSyncStatus::Running] as $index => $status) {
            $runId = '01K2XJ9E9G000000000000000'.$index;
            $connection->forceFill([
                'sync_status' => $status,
                'sync_run_id' => $runId,
                'sync_error_code' => null,
                'sync_status_at' => now(),
            ])->save();
            foreach (range(1, 2) as $_) {
                $response = $this->actingAs($user)->postJson('/api/study/google-calendar/sync')
                    ->assertStatus(202)
                    ->assertJsonPath('sync.status', $status->value);
                $this->assertArrayNotHasKey('runId', $response->json('sync'));
            }
            $this->assertSame($runId, $connection->fresh()->sync_run_id);
        }

        Queue::assertNothingPushed();
    }

    #[DataProvider('staleActiveStatusProvider')]
    public function test_stale_active_run_is_replaced_and_redispatched(GoogleCalendarSyncStatus $status): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $oldRunId = '01K2XJ9E9G0000000000000009';
        $connection = $this->connection($user, [
            'sync_status' => $status,
            'sync_run_id' => $oldRunId,
            'sync_status_at' => now()->subSeconds(GoogleCalendarSyncRun::STALE_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($user)->postJson('/api/study/google-calendar/sync')
            ->assertStatus(202)
            ->assertJsonPath('sync.status', 'queued')
            ->assertJsonPath('sync.statusAt', '2026-08-16T15:16:17Z');

        $fresh = $connection->fresh();
        $this->assertNotSame($oldRunId, $fresh->sync_run_id);
        Queue::assertPushed(SyncGoogleCalendarConnection::class, fn ($job): bool => $job->runId === $fresh->sync_run_id);
    }

    /** @return array<string, array{GoogleCalendarSyncStatus}> */
    public static function staleActiveStatusProvider(): array
    {
        return [
            'queued' => [GoogleCalendarSyncStatus::Queued],
            'running' => [GoogleCalendarSyncStatus::Running],
        ];
    }

    public function test_queue_dispatch_failure_is_redacted_and_records_a_safe_failure(): void
    {
        Exceptions::fake();
        config(['queue.default' => 'missing-manual-calendar-driver']);
        $user = User::factory()->create();
        $connection = $this->connection($user);

        $this->actingAs($user)->postJson('/api/study/google-calendar/sync')
            ->assertStatus(503)
            ->assertExactJson(['error' => [
                'code' => 'sync_unavailable',
                'message' => 'Google Calendar sync is temporarily unavailable.',
            ]])
            ->assertDontSee('missing-manual-calendar-driver');

        $fresh = $connection->fresh();
        $this->assertSame(GoogleCalendarSyncStatus::Failed, $fresh->sync_status);
        $this->assertSame('sync_failed', $fresh->sync_error_code->value);
        $this->assertNull($fresh->last_synced_at);
    }

    public function test_storage_failure_is_redacted_without_dispatching(): void
    {
        Queue::fake();
        Exceptions::fake();
        $user = User::factory()->create();
        $this->connection($user);
        $listener = static fn () => throw new RuntimeException('database-secret');
        Event::listen('eloquent.updating: '.GoogleCalendarConnection::class, $listener);

        try {
            $this->actingAs($user)->postJson('/api/study/google-calendar/sync')
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'sync_unavailable')
                ->assertDontSee('database-secret');
        } finally {
            Event::forget('eloquent.updating: '.GoogleCalendarConnection::class);
        }

        Queue::assertNothingPushed();
    }

    public function test_sync_write_limiter_returns_standard_retry_headers(): void
    {
        Queue::fake();
        $bucket = 'test-'.Str::ulid();
        $user = User::factory()->create();
        $this->connection($user);

        try {
            RateLimiter::for(
                GoogleCalendarConnectionRateLimiter::NAME,
                static fn (Request $request): Limit => Limit::perMinute(1)->by($bucket),
            );
            $this->actingAs($user)->postJson('/api/study/google-calendar/sync')->assertStatus(202);
            $this->postJson('/api/study/google-calendar/sync')
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '1')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After', '60');
        } finally {
            $limiter = new GoogleCalendarConnectionRateLimiter;
            RateLimiter::for(GoogleCalendarConnectionRateLimiter::NAME, $limiter->limit(...));
            RateLimiter::clear($bucket);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function connection(User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate(array_merge([
            'user_id' => $user->id,
            'provider_account_id' => 'account-'.$user->id,
            'account_email' => $user->email,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'],
            'settings' => $this->settings(true),
            'sync_cursors' => null,
            'connected_at' => now(),
            'last_synced_at' => null,
        ], $overrides));
    }

    /** @return array{calendarIds:list<string>,titleMatchTerms:list<string>,syncEnabled:bool} */
    private function settings(bool $enabled): array
    {
        return ['calendarIds' => ['work'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => $enabled];
    }
}
