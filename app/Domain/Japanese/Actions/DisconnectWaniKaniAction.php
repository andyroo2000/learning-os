<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Support\Facades\Cache;

final class DisconnectWaniKaniAction
{
    public function handle(int $userId): void
    {
        $lock = Cache::lock("wanikani-sync:user:{$userId}", 300);
        if (! $lock->get()) {
            throw new WaniKaniSyncInProgressException;
        }

        try {
            WaniKaniConnection::query()->where('user_id', $userId)->delete();
        } finally {
            $lock->release();
        }
    }
}
