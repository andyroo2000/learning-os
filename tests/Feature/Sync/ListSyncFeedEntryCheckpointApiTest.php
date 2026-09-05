<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryCheckpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_trims_pagination_inputs_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $before = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson("/api/sync/feed?after_checkpoint=%20{$before->checkpoint}%20&per_page=%20%2B1%20");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $first->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $before->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $first->checkpoint)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.has_more', true);
    }

    public function test_it_returns_a_stale_checkpoint_response_when_the_bookmark_is_before_the_user_feed_window(): void
    {
        $user = $this->signIn();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);

        $response = $this->getJson('/api/sync/feed?after_checkpoint=4');

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Sync checkpoint is stale; perform a full resource resync.')
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', 4)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldest->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', null)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_a_scoped_stale_checkpoint_response_when_the_bookmark_is_before_the_user_feed_window(): void
    {
        $user = $this->signIn();
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

        $response = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&operation=delete&after_checkpoint={$afterCheckpoint}");

        $response
            ->assertConflict()
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $afterCheckpoint)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldest->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }
}
