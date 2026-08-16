<?php

namespace App\Jobs;

use App\Domain\Calendar\Actions\ReconcileGoogleCalendarStudyEventsAction;
use App\Domain\Calendar\Actions\SyncGoogleCalendarEventMirrorsAction;
use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Calendar\Support\GoogleCalendarSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class SyncGoogleCalendarConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = GoogleCalendarSyncRun::STALE_AFTER_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $connectionId,
        public readonly int $userId,
        public readonly string $runId,
        public readonly bool $requireEnabled = true,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('google-calendar:'.$this->connectionId))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(SyncGoogleCalendarEventMirrorsAction $sync, ReconcileGoogleCalendarStudyEventsAction $reconcile): void
    {
        $connection = GoogleCalendarSyncRun::claim($this->connectionId, $this->runId, $this->requireEnabled);
        if ($connection === null) {
            return;
        }
        if (! $sync->handle($this->userId, $connection, CarbonImmutable::instance(now())->subYear(),
            ! $this->requireEnabled, false, $this->runId)) {
            return;
        }
        if (! GoogleCalendarSyncRun::isCurrent($this->connectionId, $this->runId)) {
            return;
        }
        $reconcile->handle($this->userId, $connection, $this->runId, ! $this->requireEnabled);
        GoogleCalendarSyncRun::finish($this->connectionId, $this->runId, GoogleCalendarSyncStatus::Succeeded);
    }

    public function failed(Throwable $e): void
    {
        try {
            GoogleCalendarSyncRun::finish($this->connectionId, $this->runId, GoogleCalendarSyncStatus::Failed,
                GoogleCalendarSyncRun::errorCode($e));
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    public function uniqueId(): string
    {
        // Per-run uniqueness suppresses redelivery; the middleware serializes different runs per connection.
        return $this->connectionId.':'.$this->runId;
    }
}
