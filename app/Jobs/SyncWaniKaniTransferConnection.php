<?php

namespace App\Jobs;

use App\Domain\Japanese\Actions\SyncWaniKaniKanjiAction;
use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;

class SyncWaniKaniTransferConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE_NAME = 'default';

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue(self::QUEUE_NAME);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SyncWaniKaniKanjiAction $sync): void
    {
        if (! WaniKaniConnection::query()
            ->where('user_id', $this->userId)
            ->where('transfer_bridge_enabled', true)
            ->exists()) {
            return;
        }

        try {
            $sync->handle($this->userId);
        } catch (WaniKaniSyncInProgressException) {
            // A manual sync owns the same work and the transfer dispatcher is idempotent.
            return;
        } catch (ModelNotFoundException $exception) {
            // Disconnect may win after the enabled preflight. Do not hide unrelated
            // missing-model failures while the connection still exists.
            if (! WaniKaniConnection::query()->where('user_id', $this->userId)->exists()) {
                return;
            }

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        // Uniqueness avoids redundant provider calls. Correctness still comes from the
        // per-user sync lock and transfer-group database uniqueness on every attempt.
        return (string) $this->userId;
    }
}
