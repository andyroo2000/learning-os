<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryScopedReplayCheckpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_resource_type_only_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = $this->signIn();
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

        $response = $this->getJson("/api/sync/feed?resource_type=card&after_checkpoint={$deck->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_allows_resource_id_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = $this->signIn();
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

        $response = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&after_checkpoint={$otherCard->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $oldestTarget->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $oldestTarget->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $oldestTarget->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_returns_an_empty_page_when_the_filtered_domain_has_no_entries(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&after_checkpoint={$media->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }
}
