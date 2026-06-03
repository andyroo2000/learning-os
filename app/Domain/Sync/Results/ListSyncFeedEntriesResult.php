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
    ) {}

    /**
     * @param  Collection<int, SyncFeedEntry>  $entries
     */
    public static function fromLookahead(Collection $entries, CursorPageSize $pageSize): self
    {
        $limit = $pageSize->value();
        $hasMore = $entries->count() > $limit;
        $pageEntries = $entries->take($limit)->values();

        return new self(
            entries: $pageEntries,
            hasMore: $hasMore,
        );
    }

    public function nextCheckpoint(int $fallbackCheckpoint): int
    {
        return $this->entries->max('checkpoint') ?? $fallbackCheckpoint;
    }
}
