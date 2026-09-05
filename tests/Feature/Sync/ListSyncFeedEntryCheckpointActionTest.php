<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryCheckpointActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_checkpoints_equal_to_the_oldest_available_entry(): void
    {
        $user = User::factory()->create();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);
        $next = SyncFeedEntry::factory()->create([
            'checkpoint' => 6,
            'user_id' => $user->id,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $oldest->checkpoint,
        );

        $this->assertSame([$next->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_allows_checkpoint_zero_before_the_oldest_available_entry(): void
    {
        $user = User::factory()->create();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: 0,
        );

        $this->assertSame([$oldest->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_allows_nonzero_checkpoints_when_the_filtered_scope_has_no_entries(): void
    {
        $user = User::factory()->create();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $media->checkpoint,
            domain: 'flashcards',
        );

        $this->assertTrue($result->entries->isEmpty());
        $this->assertFalse($result->hasMore);
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($media->checkpoint, $result->nextCheckpoint($media->checkpoint));
    }
}
