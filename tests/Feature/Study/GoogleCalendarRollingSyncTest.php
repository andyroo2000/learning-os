<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\DisconnectGoogleCalendarAction;
use App\Domain\Calendar\Actions\DispatchGoogleCalendarSyncsAction;
use App\Domain\Calendar\Actions\ReconcileGoogleCalendarStudyEventsAction;
use App\Domain\Calendar\Actions\SyncGoogleCalendarEventMirrorsAction;
use App\Domain\Calendar\Actions\UpdateGoogleCalendarSettingsAction;
use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;
use App\Domain\Calendar\Enums\GoogleCalendarSyncErrorCode;
use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Support\GoogleCalendarSyncRun;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class GoogleCalendarRollingSyncTest extends TestCase
{
    use RefreshDatabase;

    private RollingSyncTransport $google;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-16T12:00:00Z');
        $this->google = new RollingSyncTransport;
        $this->app->instance(GoogleCalendarReadTransport::class, $this->google);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_scheduler_queues_each_valid_enabled_connection_once_and_has_expected_envelope(): void
    {
        Queue::fake();
        $enabled = $this->connection(User::factory()->create());
        $this->connection(User::factory()->create(), ['settings' => $this->settings(false)]);
        $this->connection(User::factory()->create(), ['settings' => ['syncEnabled' => true]]);

        app(DispatchGoogleCalendarSyncsAction::class)->handle();
        app(DispatchGoogleCalendarSyncsAction::class)->handle();

        Queue::assertPushed(SyncGoogleCalendarConnection::class, 1);
        Queue::assertPushed(SyncGoogleCalendarConnection::class, function ($job) use ($enabled): bool {
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
            $this->assertSame([60, 300], $job->backoff());
            $this->assertSame(3, $job->tries);

            return $job->connectionId === $enabled->id && $job->requireEnabled;
        });
        $event = collect(Schedule::events())->first(fn ($event): bool => $event->description === 'calendar:sync-connections');
        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_job_runs_initial_then_rolling_sync_and_reconciliation_pipeline(): void
    {
        $connection = $this->connection($user = User::factory()->create());
        $run = GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000000');
        $this->assertNotNull($run);

        $this->job($run)->handle(app(SyncGoogleCalendarEventMirrorsAction::class), app(ReconcileGoogleCalendarStudyEventsAction::class));

        $fresh = $connection->fresh();
        $this->assertSame(GoogleCalendarSyncStatus::Succeeded, $fresh->sync_status);
        $this->assertSame([['work', '2025-08-16T12:00:00Z', null]], $this->google->calls);

        $this->google->calls = [];
        $run = GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000001');
        $this->job($run)->handle(app(SyncGoogleCalendarEventMirrorsAction::class), app(ReconcileGoogleCalendarStudyEventsAction::class));
        $this->assertSame([['work', null, 'cursor-1']], $this->google->calls);
        $this->assertSame($user->id, $connection->user_id);
    }

    public function test_manual_run_can_sync_while_disabled_but_automatic_and_stale_jobs_cannot(): void
    {
        $connection = $this->connection(User::factory()->create(), ['settings' => $this->settings(false)]);
        $this->assertNull(GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000002'));

        $run = GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000003', false);
        $this->job($run, false)->handle(app(SyncGoogleCalendarEventMirrorsAction::class), app(ReconcileGoogleCalendarStudyEventsAction::class));
        $this->assertSame(GoogleCalendarSyncStatus::Succeeded, $connection->fresh()->sync_status);

        app(UpdateGoogleCalendarSettingsAction::class)->handle($connection->user_id, GoogleCalendarSettings::make(['work'], ['changed'], false));
        $this->assertSame(GoogleCalendarSyncStatus::Idle, $connection->fresh()->sync_status);
        $newRun = GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000006', false);
        $this->job($run, false)->failed(new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE));
        $this->assertSame(GoogleCalendarSyncStatus::Queued, $connection->fresh()->sync_status);
        $this->assertSame($newRun['run'], $connection->fresh()->sync_run_id);
    }

    public function test_replaced_run_cannot_commit_a_late_provider_response(): void
    {
        $connection = $this->connection(User::factory()->create());
        $run = GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000007');
        $this->google->beforeResponse = function () use ($connection): void {
            $connection->fresh()->forceFill([
                'sync_run_id' => '01K2XJ9E9G0000000000000008',
                'sync_status' => GoogleCalendarSyncStatus::Queued,
                'sync_status_at' => now(),
            ])->save();
        };

        $this->job($run)->handle(app(SyncGoogleCalendarEventMirrorsAction::class), app(ReconcileGoogleCalendarStudyEventsAction::class));

        $fresh = $connection->fresh();
        $this->assertNull($fresh->sync_cursors);
        $this->assertSame('01K2XJ9E9G0000000000000008', $fresh->sync_run_id);
        $this->assertSame(GoogleCalendarSyncStatus::Queued, $fresh->sync_status);
    }

    public function test_failure_is_safe_and_disconnected_job_is_a_no_op(): void
    {
        $connection = $this->connection(User::factory()->create(), ['settings' => [
            'calendarIds' => ['work', 'personal'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true,
        ]]);
        $run = GoogleCalendarSyncRun::queue($connection->id, '01K2XJ9E9G0000000000000004');
        $this->google->failOnCall = 2;
        try {
            $this->job($run)->handle(app(SyncGoogleCalendarEventMirrorsAction::class), app(ReconcileGoogleCalendarStudyEventsAction::class));
            $this->fail('Expected provider failure.');
        } catch (GoogleCalendarProviderException $e) {
            $this->job($run)->failed($e);
        }
        $fresh = $connection->fresh();
        $this->assertSame(GoogleCalendarSyncStatus::Failed, $fresh->sync_status);
        $this->assertSame(GoogleCalendarSyncErrorCode::ProviderUnavailable, $fresh->sync_error_code);
        $this->assertSame(['work' => 'cursor-1'], $fresh->sync_cursors);
        $this->assertNull($fresh->last_synced_at);

        $other = $this->connection(User::factory()->create(), ['provider_account_id' => 'other']);
        $run = GoogleCalendarSyncRun::queue($other->id, '01K2XJ9E9G0000000000000005');
        app(DisconnectGoogleCalendarAction::class)->handle($other->user_id);
        $this->job($run)->handle(app(SyncGoogleCalendarEventMirrorsAction::class), app(ReconcileGoogleCalendarStudyEventsAction::class));
        $this->assertDatabaseMissing('google_calendar_connections', ['id' => $other->id]);
    }

    private function job(?array $run, bool $requireEnabled = true): SyncGoogleCalendarConnection
    {
        return new SyncGoogleCalendarConnection($run['connection'], $run['user'], $run['run'], $requireEnabled);
    }

    private function connection(User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate(array_merge([
            'user_id' => $user->id, 'provider_account_id' => 'account-'.$user->id, 'account_email' => $user->email,
            'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'], 'settings' => $this->settings(true), 'sync_cursors' => null,
            'connected_at' => now(), 'last_synced_at' => null,
        ], $overrides));
    }

    private function settings(bool $enabled): array
    {
        return ['calendarIds' => ['work'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => $enabled];
    }
}

final class RollingSyncTransport implements GoogleCalendarReadTransport
{
    public array $calls = [];

    public ?int $failOnCall = null;

    public ?\Closure $beforeResponse = null;

    public function refresh(string $refreshToken): GoogleCalendarTokenGrant
    {
        return new GoogleCalendarTokenGrant('access', 3600, null);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        return new GoogleCalendarPage([], null, null);
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        if ($this->failOnCall === count($this->calls) + 1) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        }
        ($this->beforeResponse ?? static fn () => null)();
        $this->calls[] = [$calendarId, $query->timeMin, $query->syncToken];

        return new GoogleCalendarPage([], null, 'cursor-1');
    }
}
