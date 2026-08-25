<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Jobs\SyncWaniKaniTransferConnection;
use Throwable;

final class RunDailyWaniKaniTransferBridgeAction
{
    /** @return array{queued: int, failed: int} */
    public function handle(): array
    {
        $queued = 0;
        $failed = 0;

        WaniKaniConnection::query()
            ->where('transfer_bridge_enabled', true)
            ->orderBy('id')
            ->chunkById(100, function ($connections) use (&$failed, &$queued): void {
                foreach ($connections as $connection) {
                    try {
                        SyncWaniKaniTransferConnection::dispatch($connection->user_id);
                        $queued++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        return ['queued' => $queued, 'failed' => $failed];
    }
}
