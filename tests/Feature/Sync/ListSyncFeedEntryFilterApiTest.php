<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListSyncFeedEntryFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_entries_by_domain(): void
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
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonMissing([
                'checkpoint' => $media->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_resource_type(): void
    {
        $user = $this->signIn();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);

        $response = $this->getJson('/api/sync/feed?resource_type=card');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $card->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $deck->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $deck->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_and_resource_type(): void
    {
        $user = $this->signIn();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $mediaCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $card->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $mediaCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $mediaCard->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $deck->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $mediaCard->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_resource_type_and_resource_id(): void
    {
        $user = $this->signIn();
        $targetCreate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Create,
        ]);
        $targetUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Update,
        ]);
        $otherCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);
        $deckSameId = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'resource_id' => 'card-1',
        ]);
        $mediaSameId = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $targetCreate->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $targetUpdate->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $mediaSameId->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $mediaSameId->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $otherCard->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $deckSameId->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $mediaSameId->checkpoint,
            ]);
    }

    public function test_it_normalizes_uppercase_ulid_resource_id_filters(): void
    {
        $user = $this->signIn();
        $resourceId = strtolower((string) Str::ulid());
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => $resourceId,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=FLASHCARDS&resource_type=CARD&resource_id='.strtoupper($resourceId));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', $resourceId);
    }

    public function test_it_normalizes_uppercase_composite_resource_id_filters(): void
    {
        $user = $this->signIn();
        $cardId = strtolower((string) Str::ulid());
        $mediaAssetId = strtolower((string) Str::ulid());
        $resourceId = "{$cardId}:{$mediaAssetId}";
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card_media',
            'resource_id' => $resourceId,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=MEDIA&resource_type=CARD_MEDIA&resource_id='.strtoupper($resourceId));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.domain', 'media')
            ->assertJsonPath('meta.resource_type', 'card_media')
            ->assertJsonPath('meta.resource_id', $resourceId);
    }
}
