<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Japanese\Services\WaniKaniApiClient;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ConnectWaniKaniAction
{
    public function __construct(private readonly WaniKaniApiClient $client) {}

    public function handle(int $userId, string $apiToken): WaniKaniConnection
    {
        $this->client->validateToken($apiToken);

        $lock = Cache::lock("wanikani-sync:user:{$userId}", 300);
        if (! $lock->get()) {
            throw new WaniKaniSyncInProgressException;
        }

        try {
            return DB::transaction(function () use ($userId, $apiToken): WaniKaniConnection {
                User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                $connection = WaniKaniConnection::query()->where('user_id', $userId)->first() ?? new WaniKaniConnection;
                $tokenChanged = ! $connection->exists || ! hash_equals((string) $connection->api_token, $apiToken);
                $connection->user_id = $userId;
                $connection->api_token = $apiToken;
                if ($tokenChanged) {
                    $connection->assignments_synced_through_at = null;
                    $connection->last_synced_at = null;
                }
                $connection->save();

                return $connection;
            });
        } finally {
            $lock->release();
        }
    }
}
