<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryCompletePaginationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_no_more_entries_when_the_page_is_exactly_full(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertCount(2, $result->entries);
        $this->assertFalse($result->hasMore);
    }

    public function test_it_reports_no_more_entries_when_a_domain_filtered_page_is_exactly_full(): void
    {
        $user = User::factory()->create();
        $firstFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: 'flashcards',
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertSame([
            $firstFlashcards->checkpoint,
            $secondFlashcards->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($result->currentCheckpoint, $result->nextCheckpoint(0));
    }
}
