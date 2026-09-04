<?php

namespace App\Domain\Calendar\Support;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Enums\GoogleCalendarSyncErrorCode;
use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GoogleCalendarSyncRun
{
    // Exceeds 3 x 300-second attempts plus the 60/300-second retry delays.
    public const STALE_AFTER_SECONDS = 1800;

    /** @return array<string, mixed> */
    public static function resetAttributes(): array
    {
        return ['sync_status' => GoogleCalendarSyncStatus::Idle, 'sync_run_id' => null,
            'sync_error_code' => null, 'sync_status_at' => now()];
    }

    /** @return array{connection:int,user:int,run:string}|null */
    public static function queue(
        int $connectionId,
        string $runId,
        bool $requireEnabled = true,
        ?Closure $afterCommit = null,
    ): ?array {
        return DB::transaction(static function () use ($connectionId, $runId, $requireEnabled, $afterCommit): ?array {
            $connection = GoogleCalendarConnection::query()->whereKey($connectionId)->lockForUpdate()->first();
            $settings = $connection === null ? null : GoogleCalendarSettings::fromStored($connection->settings);
            if ($settings === null) {
                return null;
            }
            if ($requireEnabled && ! $settings->syncEnabled) {
                return null;
            }
            if (in_array($connection->sync_status, [GoogleCalendarSyncStatus::Queued, GoogleCalendarSyncStatus::Running], true)
                && $connection->sync_status_at?->isAfter(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
                return null;
            }
            $connection->forceFill(['sync_status' => GoogleCalendarSyncStatus::Queued, 'sync_run_id' => $runId,
                'sync_error_code' => null, 'sync_status_at' => now()])->save();

            $run = ['connection' => (int) $connection->id, 'user' => (int) $connection->user_id, 'run' => $runId];
            if ($afterCommit !== null) {
                DB::afterCommit(static fn () => $afterCommit($run));
            }

            return $run;
        });
    }

    /** @param Closure(array{connection:int,user:int,run:string}):void $afterCommit */
    public static function queueManual(int $userId, string $runId, Closure $afterCommit): ?GoogleCalendarConnection
    {
        return DB::transaction(static function () use ($userId, $runId, $afterCommit): ?GoogleCalendarConnection {
            $connection = GoogleCalendarConnection::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if (GoogleCalendarSettings::fromStored($connection->settings) === null) {
                return null;
            }
            if (in_array($connection->sync_status, [GoogleCalendarSyncStatus::Queued, GoogleCalendarSyncStatus::Running], true)
                && $connection->sync_status_at?->isAfter(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
                return $connection;
            }

            $connection->forceFill([
                'sync_status' => GoogleCalendarSyncStatus::Queued,
                'sync_run_id' => $runId,
                'sync_error_code' => null,
                'sync_status_at' => now(),
            ])->save();
            DB::afterCommit(static fn () => $afterCommit([
                'connection' => (int) $connection->id,
                'user' => (int) $connection->user_id,
                'run' => $runId,
            ]));

            return $connection;
        });
    }

    public static function claim(int $connectionId, string $runId, bool $requireEnabled = true): ?GoogleCalendarConnection
    {
        return DB::transaction(static function () use ($connectionId, $runId, $requireEnabled): ?GoogleCalendarConnection {
            $connection = GoogleCalendarConnection::query()->whereKey($connectionId)->lockForUpdate()->first();
            $settings = $connection === null ? null : GoogleCalendarSettings::fromStored($connection->settings);
            if ($connection === null) {
                return null;
            }
            if ($connection->sync_run_id !== $runId) {
                return null;
            }
            if ($settings === null) {
                return null;
            }
            if ($requireEnabled && ! $settings->syncEnabled) {
                return null;
            }
            if (! in_array($connection->sync_status, [GoogleCalendarSyncStatus::Queued, GoogleCalendarSyncStatus::Running], true)) {
                return null;
            }
            $connection->forceFill(['sync_status' => GoogleCalendarSyncStatus::Running, 'sync_status_at' => now()])->save();

            return $connection;
        });
    }

    public static function isCurrent(int $connectionId, string $runId): bool
    {
        return GoogleCalendarConnection::query()->whereKey($connectionId)->where('sync_run_id', $runId)
            ->where('sync_status', GoogleCalendarSyncStatus::Running->value)->exists();
    }

    public static function finish(int $connectionId, string $runId, GoogleCalendarSyncStatus $status, ?GoogleCalendarSyncErrorCode $error = null): void
    {
        $attributes = ['sync_status' => $status->value, 'sync_error_code' => $error?->value, 'sync_status_at' => now()];
        if ($status === GoogleCalendarSyncStatus::Succeeded) {
            $attributes['last_synced_at'] = now();
        }
        GoogleCalendarConnection::query()->whereKey($connectionId)->where('sync_run_id', $runId)
            ->whereIn('sync_status', [GoogleCalendarSyncStatus::Queued->value, GoogleCalendarSyncStatus::Running->value])
            ->update($attributes);
    }

    public static function errorCode(Throwable $e): GoogleCalendarSyncErrorCode
    {
        if (! $e instanceof GoogleCalendarProviderException) {
            return GoogleCalendarSyncErrorCode::SyncFailed;
        }

        return match ($e->reason()) {
            GoogleCalendarProviderException::RECONNECT_REQUIRED => GoogleCalendarSyncErrorCode::ReconnectRequired,
            GoogleCalendarProviderException::UNAVAILABLE => GoogleCalendarSyncErrorCode::ProviderUnavailable,
            GoogleCalendarProviderException::INVALID_RESPONSE, GoogleCalendarProviderException::INVALID_REQUEST => GoogleCalendarSyncErrorCode::InvalidProviderResponse,
            default => GoogleCalendarSyncErrorCode::SyncFailed,
        };
    }
}
