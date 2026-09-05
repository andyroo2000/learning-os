<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryReplayCheckpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_filtered_replay_reports_global_high_water_after_the_last_matching_entry(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'media',
        ]);
        $oldestFlashcard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $globalHighWater = SyncFeedEntry::factory()->create([
            'checkpoint' => 6,
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&after_checkpoint={$media->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $oldestFlashcard->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', null)
            ->assertJsonPath('meta.current_checkpoint', $globalHighWater->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $globalHighWater->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_allows_operation_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
    {
        $user = $this->signIn();
        $update = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);
        $oldestDelete = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$update->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $oldestDelete->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $oldestDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $oldestDelete->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_allows_resource_type_filtered_replay_from_a_global_checkpoint_before_the_first_matching_entry(): void
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
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&after_checkpoint={$deck->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }
}
