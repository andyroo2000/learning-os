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
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?CursorPageSize $pageSize = null,
    ): ListSyncFeedEntriesResult {
        if ($userId < 1) {
            throw new LogicException('Sync feed user ID must be a positive integer.');
        }

        if ($afterCheckpoint < 0) {
            throw new InvalidArgumentException('Sync feed checkpoint must be zero or greater.');
        }

        $domain = $domain === null ? null : trim($domain);
        $resourceType = $resourceType === null ? null : trim($resourceType);
        $resourceId = $resourceId === null ? null : trim($resourceId);

        if ($domain === '') {
            throw new InvalidArgumentException('Sync feed domain must not be blank when provided.');
        }

        if ($resourceType === '') {
            throw new InvalidArgumentException('Sync feed resource_type must not be blank when provided.');
        }

        if ($resourceId === '') {
            throw new InvalidArgumentException('Sync feed resource_id must not be blank when provided.');
        }

        if ($resourceId !== null && ($domain === null || $resourceType === null)) {
            throw new InvalidArgumentException('Sync feed resource_id filters require both domain and resource_type.');
        }

        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        $userFeedQuery = SyncFeedEntry::query()
            ->where('user_id', $userId);

        $baseQuery = (clone $userFeedQuery)
            ->when($domain !== null, fn ($query) => $query->where('domain', $domain))
            ->when($resourceType !== null, fn ($query) => $query->where('resource_type', $resourceType))
            ->when($resourceId !== null, fn ($query) => $query->where('resource_id', $resourceId));

        $currentCheckpoint = (int) (clone $userFeedQuery)->max('checkpoint');

        if ($afterCheckpoint > 0) {
            $oldestAvailableCheckpoint = (clone $baseQuery)->min('checkpoint');

            if ($oldestAvailableCheckpoint !== null && $afterCheckpoint < (int) $oldestAvailableCheckpoint) {
                throw StaleSyncFeedCheckpointException::forCheckpoint(
                    afterCheckpoint: $afterCheckpoint,
                    oldestAvailableCheckpoint: (int) $oldestAvailableCheckpoint,
                    domain: $domain,
                    resourceType: $resourceType,
                    resourceId: $resourceId,
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
