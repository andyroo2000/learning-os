<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Sync\Results\ListSyncFeedEntriesResult;
use App\Domain\Sync\Support\SyncFeedFilters;
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
        ?string $operation = null,
        ?CursorPageSize $pageSize = null,
    ): ListSyncFeedEntriesResult {
        if ($userId < 1) {
            throw new LogicException('Sync feed user ID must be a positive integer.');
        }

        if ($afterCheckpoint < 0) {
            throw new InvalidArgumentException('Sync feed checkpoint must be zero or greater.');
        }

        [$domain, $resourceType, $resourceId, $operation] = SyncFeedFilters::fromInput(
            $domain,
            $resourceType,
            $resourceId,
            $operation,
        );

        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        // Keep this query filter-free after user scope; stale checks and high-water metadata use the global feed window.
        $userFeedQuery = SyncFeedEntry::query()
            ->where('user_id', $userId);

        $baseQuery = (clone $userFeedQuery)
            ->when($domain !== null, fn ($query) => $query->where('domain', $domain))
            ->when($resourceType !== null, fn ($query) => $query->where('resource_type', $resourceType))
            ->when($resourceId !== null, fn ($query) => $query->where('resource_id', $resourceId))
            ->when($operation !== null, fn ($query) => $query->where('operation', $operation));

        $checkpointWindow = (clone $userFeedQuery)
            ->selectRaw('MIN(checkpoint) as oldest_checkpoint, MAX(checkpoint) as current_checkpoint')
            ->first();

        // Aggregate first() returns a row; oldest/current are null only when the user's feed is empty.
        $oldestAvailableCheckpoint = $checkpointWindow->oldest_checkpoint === null
            ? null
            : (int) $checkpointWindow->oldest_checkpoint;
        $currentCheckpoint = $checkpointWindow->current_checkpoint === null
            ? 0
            : (int) $checkpointWindow->current_checkpoint;

        if ($afterCheckpoint > 0) {
            // Staleness is a retained-feed property, not a filtered-view property. A client can safely
            // replay a filtered slice from any checkpoint that still falls inside the user's global window.
            if ($oldestAvailableCheckpoint !== null && $afterCheckpoint < $oldestAvailableCheckpoint) {
                throw StaleSyncFeedCheckpointException::forCheckpoint(
                    afterCheckpoint: $afterCheckpoint,
                    oldestAvailableCheckpoint: $oldestAvailableCheckpoint,
                    domain: $domain,
                    resourceType: $resourceType,
                    resourceId: $resourceId,
                    operation: $operation,
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
