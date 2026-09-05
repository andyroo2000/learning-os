<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryScopedReplayCheckpointActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_resource_type_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = User::factory()->create();
        $deck = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $oldestCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $deck->checkpoint,
            domain: 'flashcards',
            resourceType: 'card',
        );

        $this->assertSame([$oldestCard->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($oldestCard->checkpoint, $result->nextCheckpoint($deck->checkpoint));
    }

    public function test_it_allows_resource_type_only_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = User::factory()->create();
        $deck = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $oldestCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $deck->checkpoint,
            resourceType: 'card',
        );

        $this->assertSame([$oldestCard->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($oldestCard->checkpoint, $result->nextCheckpoint($deck->checkpoint));
    }

    public function test_it_allows_resource_id_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = User::factory()->create();
        $otherCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);
        $oldestTarget = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $otherCard->checkpoint,
            domain: 'flashcards',
            resourceType: 'card',
            resourceId: 'card-1',
        );

        $this->assertSame([$oldestTarget->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($oldestTarget->checkpoint, $result->nextCheckpoint($otherCard->checkpoint));
    }
}
