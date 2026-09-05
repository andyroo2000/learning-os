<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntriesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_empty_list_when_the_user_has_no_new_entries(): void
    {
        $user = $this->signIn();
        $entry = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/sync/feed?after_checkpoint={$entry->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    public function test_it_reports_zero_current_checkpoint_when_the_user_feed_is_empty(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', 0)
            ->assertJsonPath('meta.current_checkpoint', 0)
            ->assertJsonPath('meta.next_checkpoint', 0)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_lists_the_full_feed_when_after_checkpoint_is_omitted(): void
    {
        $user = $this->signIn();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $first->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', 0)
            ->assertJsonPath('meta.current_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/sync/feed');

        $response->assertUnauthorized();
    }
}
