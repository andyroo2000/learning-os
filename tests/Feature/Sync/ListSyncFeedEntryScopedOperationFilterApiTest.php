<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryScopedOperationFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_entries_by_domain_and_operation(): void
    {
        $user = $this->signIn();
        $flashcardDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'operation' => SyncFeedOperation::Delete,
        ]);
        $flashcardUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'operation' => SyncFeedOperation::Update,
        ]);
        $mediaDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $flashcardDelete->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $mediaDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $mediaDelete->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $flashcardUpdate->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $mediaDelete->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_resource_type_and_operation(): void
    {
        $user = $this->signIn();
        $cardDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'operation' => SyncFeedOperation::Delete,
        ]);
        $cardUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'operation' => SyncFeedOperation::Update,
        ]);
        $deckDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $cardDelete->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $deckDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $deckDelete->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $cardUpdate->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $deckDelete->checkpoint,
            ]);
    }

    public function test_it_returns_an_empty_page_when_the_filtered_operation_has_no_entries(): void
    {
        $user = $this->signIn();
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $response = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$update->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }
}
