<?php

namespace App\Domain\Content\Support;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use RuntimeException;

final class ContentSourceLock
{
    public static function acquireConvoLab(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('The Convo Lab content source lock requires an active transaction.');
        }

        $lock = $connection->table('content_source_locks')
            ->where('source_system', ContentSourceSystem::CONVOLAB)
            ->lockForUpdate()
            ->first();

        if ($lock === null) {
            throw new RuntimeException('The Convo Lab content source lock is missing.');
        }
    }
}
