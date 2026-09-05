<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryScopedPaginationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_next_checkpoint_to_continue_resource_id_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $secondTarget = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $thirdTarget = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $otherCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);

        $firstPage = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondTarget->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdTarget->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondTarget->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $otherCard->checkpoint,
            ]);
    }

    public function test_it_uses_next_checkpoint_to_continue_operation_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $secondDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $thirdDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $firstPage = $this->getJson('/api/sync/feed?operation=delete&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondDelete->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdDelete->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondDelete->checkpoint)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $update->checkpoint,
            ]);
    }
}
