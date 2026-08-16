<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Enums\GoogleCalendarSyncErrorCode;
use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Support\GoogleCalendarSyncRun;
use App\Jobs\SyncGoogleCalendarConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

final class DispatchGoogleCalendarSyncsAction
{
    public function handle(): void
    {
        GoogleCalendarConnection::query()->select('id')->orderBy('id')->chunkById(100,
            function (Collection $connections): void {
                foreach ($connections as $connection) {
                    $this->connection((int) $connection->id);
                }
            });
    }

    /** The future manual-sync endpoint calls this with $requireEnabled=false. */
    public function connection(int $connectionId, bool $requireEnabled = true): bool
    {
        $run = null;
        try {
            $run = GoogleCalendarSyncRun::queue(
                $connectionId,
                (string) Str::ulid(),
                $requireEnabled,
                static function (array $queued) use ($requireEnabled): void {
                    try {
                        SyncGoogleCalendarConnection::dispatch(
                            $queued['connection'], $queued['user'], $queued['run'], $requireEnabled,
                        );
                    } catch (Throwable $e) {
                        report($e);
                        try {
                            GoogleCalendarSyncRun::finish($queued['connection'], $queued['run'],
                                GoogleCalendarSyncStatus::Failed, GoogleCalendarSyncErrorCode::SyncFailed);
                        } catch (Throwable $failure) {
                            report($failure);
                        }
                    }
                },
            );
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return $run !== null;
    }
}
