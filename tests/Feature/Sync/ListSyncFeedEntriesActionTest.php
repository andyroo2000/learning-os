<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntriesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_user_feed_entries_after_a_checkpoint_in_replay_order(): void
    {
        $user = User::factory()->create();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $third = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $first->checkpoint,
        );

        $this->assertSame([
            $second->checkpoint,
            $third->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($third->checkpoint, $result->currentCheckpoint);
        $this->assertSame($third->checkpoint, $result->nextCheckpoint($first->checkpoint));
    }

    public function test_it_lists_the_full_user_feed_when_checkpoint_is_zero(): void
    {
        $user = User::factory()->create();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: 0,
        );

        $this->assertSame([
            $first->checkpoint,
            $second->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
    }

    public function test_it_scopes_entries_to_the_requested_user(): void
    {
        $user = User::factory()->create();
        $visible = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        SyncFeedEntry::factory()->create(['user_id' => User::factory()->create()->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle($user->id);

        $this->assertSame([$visible->checkpoint], $result->entries->pluck('checkpoint')->all());
    }
}
