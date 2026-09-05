<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryPaginationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $third = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_checkpoint', $third->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_it_reports_no_more_entries_when_the_page_is_exactly_full(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_uses_next_checkpoint_to_continue_to_the_next_page(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $third = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $firstPage = $this->getJson('/api/sync/feed?per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?after_checkpoint={$nextCheckpoint}&per_page=2");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $third->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $third->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }
}
