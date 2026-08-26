<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateWaniKaniTransferBridgeAction
{
    public function __construct(
        private readonly DispatchWaniKaniTransferImportsAction $dispatchTransferImports,
    ) {}

    public function handle(int $userId, bool $enabled): WaniKaniConnection
    {
        $lock = Cache::lock("wanikani-sync:user:{$userId}", 300);
        if (! $lock->get()) {
            throw new WaniKaniSyncInProgressException;
        }

        try {
            $connection = DB::transaction(function () use ($enabled, $userId): WaniKaniConnection {
                $connection = WaniKaniConnection::query()
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($enabled && ! $connection->transfer_bridge_enabled) {
                    $connection->transfer_bridge_enabled_at = now();
                }
                $connection->transfer_bridge_enabled = $enabled;
                $connection->save();

                return $connection;
            });

            if ($enabled) {
                try {
                    // Import already-synced vocabulary immediately; future syncs and the daily
                    // scheduler use the same idempotent dispatcher.
                    $this->dispatchTransferImports->handle($userId);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return $connection;
        } finally {
            $lock->release();
        }
    }
}
