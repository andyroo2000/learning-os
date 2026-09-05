<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryFilteredPaginationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_next_checkpoint_to_continue_domain_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $thirdFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $firstPage = $this->getJson('/api/sync/feed?domain=flashcards&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?domain=flashcards&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdFlashcards->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $media->checkpoint,
            ]);
    }

    public function test_it_uses_next_checkpoint_to_continue_resource_type_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $secondCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $thirdCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);

        $firstPage = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondCard->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdCard->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondCard->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $deck->checkpoint,
            ]);
    }
}
