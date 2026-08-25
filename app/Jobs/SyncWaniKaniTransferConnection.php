<?php

namespace App\Jobs;

use App\Domain\Japanese\Actions\SyncWaniKaniKanjiAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        $sync->handle($this->userId);
    }

    public function uniqueId(): string
    {
        // Uniqueness avoids redundant provider calls. Correctness still comes from the
        // per-user sync lock and transfer-group database uniqueness on every attempt.
        return (string) $this->userId;
    }
}
