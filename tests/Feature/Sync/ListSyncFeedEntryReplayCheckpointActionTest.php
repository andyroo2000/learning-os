<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryReplayCheckpointActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_filtered_replay_reports_global_high_water_after_the_last_matching_entry(): void
    {
        $user = User::factory()->create();
        $media = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'media',
        ]);
        $oldestFlashcard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $globalHighWater = SyncFeedEntry::factory()->create([
            'checkpoint' => 6,
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $media->checkpoint,
            domain: 'flashcards',
        );

        $this->assertSame([$oldestFlashcard->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($globalHighWater->checkpoint, $result->currentCheckpoint);
        $this->assertSame($globalHighWater->checkpoint, $result->nextCheckpoint($media->checkpoint));
    }

    public function test_it_allows_operation_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = User::factory()->create();
        $update = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);
        $oldestDelete = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $update->checkpoint,
            operation: SyncFeedOperation::Delete->value,
        );

        $this->assertSame([$oldestDelete->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($oldestDelete->checkpoint, $result->nextCheckpoint($update->checkpoint));
    }
}
