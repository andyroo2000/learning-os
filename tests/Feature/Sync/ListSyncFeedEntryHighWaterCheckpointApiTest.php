<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryHighWaterCheckpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_complete_domain_filtered_page_advances_to_the_user_feed_high_water_mark(): void
    {
        $user = $this->signIn();
        $flashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $flashcards->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_a_cold_start_domain_filter_with_no_entries_advances_to_the_user_feed_high_water_mark(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&after_checkpoint=0');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', 0)
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_a_complete_domain_filtered_page_uses_the_domain_checkpoint_when_it_is_the_high_water_mark(): void
    {
        $user = $this->signIn();
        $firstFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $firstFlashcards->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }
}
