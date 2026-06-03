<?php

namespace App\Domain\Sync\Results;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Support\Collection;

final readonly class ListSyncFeedEntriesResult
{
    /**
     * @param  Collection<int, SyncFeedEntry>  $entries
     */
    private function __construct(
        public Collection $entries,
        public bool $hasMore,
        public int $currentCheckpoint,
    ) {}

    /**
     * @param  Collection<int, SyncFeedEntry>  $entries
     */
    public static function fromLookahead(Collection $entries, CursorPageSize $pageSize, int $currentCheckpoint): self
    {
        $limit = $pageSize->value();
        $hasMore = $entries->count() > $limit;
        $pageEntries = $entries->take($limit)->values();

        return new self(
            entries: $pageEntries,
            hasMore: $hasMore,
            currentCheckpoint: $currentCheckpoint,
        );
    }

    public function nextCheckpoint(int $fallbackCheckpoint): int
    {
        $pageCheckpoint = $this->entries->max('checkpoint');

        if ($this->hasMore && $pageCheckpoint !== null) {
            return (int) $pageCheckpoint;
        }

        return max($fallbackCheckpoint, $this->currentCheckpoint);
    }
}
