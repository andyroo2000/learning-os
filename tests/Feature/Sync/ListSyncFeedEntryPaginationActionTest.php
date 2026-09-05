<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryPaginationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $result->entries);
        $this->assertTrue($result->hasMore);
        $this->assertGreaterThan(
            $result->entries->last()->checkpoint,
            $result->currentCheckpoint,
        );
        $this->assertSame(
            $result->entries->last()->checkpoint,
            $result->nextCheckpoint(0),
        );
    }

    public function test_it_uses_the_default_page_size_when_none_is_provided(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle($user->id);

        $this->assertCount(CursorPagination::DEFAULT_PAGE_SIZE, $result->entries);
        $this->assertTrue($result->hasMore);
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(0),
        );

        $this->assertCount(1, $result->entries);
        $this->assertTrue($result->hasMore);
    }
}
