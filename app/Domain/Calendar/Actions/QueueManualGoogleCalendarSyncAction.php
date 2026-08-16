<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Enums\GoogleCalendarSyncErrorCode;
use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Calendar\Exceptions\GoogleCalendarManualSyncException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Support\GoogleCalendarSyncRun;
use App\Jobs\SyncGoogleCalendarConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Throwable;

final class QueueManualGoogleCalendarSyncAction
{
    public function handle(int $userId): GoogleCalendarConnection
    {
        $dispatchFailed = false;

        try {
            $result = GoogleCalendarSyncRun::queueManual(
                $userId,
                (string) Str::ulid(),
                static function (array $queued) use (&$dispatchFailed): void {
                    try {
                        SyncGoogleCalendarConnection::dispatch(
                            $queued['connection'],
                            $queued['user'],
                            $queued['run'],
                            false,
                        );
                    } catch (Throwable $e) {
                        $dispatchFailed = true;
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
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new GoogleCalendarManualSyncException(GoogleCalendarManualSyncException::SYNC_UNAVAILABLE);
        }

        if ($result === null) {
            throw new GoogleCalendarManualSyncException(GoogleCalendarManualSyncException::SETTINGS_REQUIRED);
        }
        if ($dispatchFailed) {
            throw new GoogleCalendarManualSyncException(GoogleCalendarManualSyncException::SYNC_UNAVAILABLE);
        }

        try {
            return $result->fresh();
        } catch (Throwable $e) {
            report($e);
            throw new GoogleCalendarManualSyncException(GoogleCalendarManualSyncException::SYNC_UNAVAILABLE);
        }
    }
}
