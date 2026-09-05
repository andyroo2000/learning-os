<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryPartialPaginationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_filtered_partial_pages_keep_next_checkpoint_at_the_page_boundary(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        SyncFeedEntry::factory()->create([
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

        $this->assertTrue($result->hasMore);
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($secondFlashcards->checkpoint, $result->nextCheckpoint(0));
    }

    public function test_resource_type_filtered_partial_pages_keep_next_checkpoint_at_the_page_boundary(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $secondCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: 'flashcards',
            resourceType: 'card',
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertTrue($result->hasMore);
        $this->assertSame($deck->checkpoint, $result->currentCheckpoint);
        $this->assertSame($secondCard->checkpoint, $result->nextCheckpoint(0));
    }

    public function test_operation_filtered_partial_pages_keep_next_checkpoint_at_the_page_boundary(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $secondDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            operation: SyncFeedOperation::Delete->value,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertTrue($result->hasMore);
        $this->assertSame($update->checkpoint, $result->currentCheckpoint);
        $this->assertSame($secondDelete->checkpoint, $result->nextCheckpoint(0));
    }
}
