<?php

namespace Tests\Unit\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Sync\Results\ListSyncFeedEntriesResult;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ListSyncFeedEntriesResultTest extends TestCase
{
    public function test_complete_pages_never_return_a_checkpoint_before_delivered_entries(): void
    {
        $entries = new Collection([
            $this->syncFeedEntryWithCheckpoint(10),
            $this->syncFeedEntryWithCheckpoint(11),
        ]);

        $result = ListSyncFeedEntriesResult::fromLookahead(
            entries: $entries,
            pageSize: CursorPageSize::fromPerPage(2),
            currentCheckpoint: 9,
        );

        $this->assertFalse($result->hasMore);
        $this->assertSame(11, $result->nextCheckpoint(0));
    }

    private function syncFeedEntryWithCheckpoint(int $checkpoint): SyncFeedEntry
    {
        $entry = new SyncFeedEntry;
        $entry->setRawAttributes(['checkpoint' => $checkpoint], sync: true);

        return $entry;
    }
}
