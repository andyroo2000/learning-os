<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryStaleCheckpointActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_stale_checkpoints_before_the_oldest_available_user_entry(): void
    {
        $user = User::factory()->create();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: 4,
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame('Sync checkpoint is stale; perform a full resource resync.', $exception->getMessage());
            $this->assertSame(4, $exception->afterCheckpoint());
            $this->assertSame($oldest->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertNull($exception->domain());
            $this->assertNull($exception->resourceType());
            $this->assertNull($exception->resourceId());
            $this->assertNull($exception->operation());
            $this->assertSame(StaleSyncFeedCheckpointException::REASON, $exception->reason());
            $this->assertSame(StaleSyncFeedCheckpointException::REQUIRED_ACTION, $exception->requiredAction());
        }
    }

    public function test_it_rejects_stale_checkpoints_before_the_user_feed_window_with_filter_context(): void
    {
        $user = User::factory()->create();
        $pruned = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $afterCheckpoint = $pruned->checkpoint;

        $pruned->delete();

        $oldest = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Delete,
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: $afterCheckpoint,
                domain: 'flashcards',
                resourceType: 'card',
                resourceId: 'card-1',
                operation: SyncFeedOperation::Delete->value,
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame($afterCheckpoint, $exception->afterCheckpoint());
            $this->assertSame($oldest->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertSame('flashcards', $exception->domain());
            $this->assertSame('card', $exception->resourceType());
            $this->assertSame('card-1', $exception->resourceId());
            $this->assertSame(SyncFeedOperation::Delete->value, $exception->operation());
        }
    }
}
