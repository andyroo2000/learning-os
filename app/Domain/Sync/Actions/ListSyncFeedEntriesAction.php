<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Sync\Results\ListSyncFeedEntriesResult;
use App\Support\Pagination\CursorPageSize;
use InvalidArgumentException;
use LogicException;

class ListSyncFeedEntriesAction
{
    public function handle(
        int $userId,
        int $afterCheckpoint = 0,
        ?string $domain = null,
        ?CursorPageSize $pageSize = null,
    ): ListSyncFeedEntriesResult {
        if ($userId < 1) {
            throw new LogicException('Sync feed user ID must be a positive integer.');
        }

        if ($afterCheckpoint < 0) {
            throw new InvalidArgumentException('Sync feed checkpoint must be zero or greater.');
        }

        $domain = $domain === null ? null : trim($domain);

        if ($domain === '') {
            throw new InvalidArgumentException('Sync feed domain must not be blank when provided.');
        }

        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        $userFeedQuery = SyncFeedEntry::query()
            ->where('user_id', $userId);

        $baseQuery = (clone $userFeedQuery)
            ->when($domain !== null, fn ($query) => $query->where('domain', $domain));

        $currentCheckpoint = (int) ((clone $userFeedQuery)->max('checkpoint') ?? 0);

        if ($afterCheckpoint > 0) {
            $oldestAvailableCheckpoint = (clone $baseQuery)->min('checkpoint');

            if ($oldestAvailableCheckpoint !== null && $afterCheckpoint < (int) $oldestAvailableCheckpoint) {
                throw StaleSyncFeedCheckpointException::forCheckpoint(
                    afterCheckpoint: $afterCheckpoint,
                    oldestAvailableCheckpoint: (int) $oldestAvailableCheckpoint,
                    domain: $domain,
                );
            }
        }

        $entries = (clone $baseQuery)
            ->where('checkpoint', '>', $afterCheckpoint)
            ->orderBy('checkpoint')
            ->limit($pageSize->value() + 1)
            ->get();

        return ListSyncFeedEntriesResult::fromLookahead($entries, $pageSize, $currentCheckpoint);
    }
}
