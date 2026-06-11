<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Sync\Results\ListSyncFeedEntriesResult;
use App\Domain\Sync\Support\SyncFeedMetadata;
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

        // Direct callers skip HTTP request normalization, so keep this action boundary canonical.
        $domain = $domain === null ? null : SyncFeedMetadata::normalize($domain);
        $resourceType = $resourceType === null ? null : SyncFeedMetadata::normalize($resourceType);
        $resourceId = $resourceId === null ? null : SyncFeedMetadata::normalize($resourceId);
        $operation = $operation === null ? null : SyncFeedMetadata::normalize($operation);

        if ($domain === '') {
            throw new InvalidArgumentException('Sync feed domain must not be blank when provided.');
        }

        if ($resourceType === '') {
            throw new InvalidArgumentException('Sync feed resource_type must not be blank when provided.');
        }

        if ($resourceId === '') {
            throw new InvalidArgumentException('Sync feed resource_id must not be blank when provided.');
        }

        $this->assertFilterLength('domain', $domain, SyncFeedEntry::MAX_DOMAIN_LENGTH);
        $this->assertFilterLength('resource_type', $resourceType, SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH);
        $this->assertFilterLength('resource_id', $resourceId, SyncFeedEntry::MAX_RESOURCE_ID_LENGTH);

        if ($operation === '') {
            throw new InvalidArgumentException('Sync feed operation must not be blank when provided.');
        }

        if ($operation !== null) {
            if (SyncFeedOperation::tryFrom($operation) === null) {
                throw new InvalidArgumentException('Sync feed operation must be one of: '.implode(', ', SyncFeedOperation::values()).'.');
            }
        }

        if ($resourceId !== null && ($domain === null || $resourceType === null)) {
            throw new InvalidArgumentException('Sync feed resource_id filters require both domain and resource_type.');
        }

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

    private function assertFilterLength(string $field, ?string $value, int $maxLength): void
    {
        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Sync feed {$field} must not exceed {$maxLength} characters.");
        }
    }
}
