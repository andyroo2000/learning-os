<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryPageSizeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed');

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::DEFAULT_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page='.CursorPagination::MIN_PAGE_SIZE);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MIN_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', CursorPagination::MIN_PAGE_SIZE);
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page='.CursorPagination::MAX_PAGE_SIZE);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);
    }
}
