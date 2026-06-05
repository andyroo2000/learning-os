<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteStudyCardCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_an_owned_study_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->deleteJson('/api/study/cards/'.strtoupper($card->id));

        $response->assertNoContent();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('card', $entry->resource_type);
        $this->assertSame($card->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame($card->id, $entry->payload['id']);
        $this->assertSame($card->deck_id, $entry->payload['deck_id']);
        $this->assertNotNull($entry->payload['deleted_at']);
    }

    public function test_it_is_idempotent_for_owned_soft_deleted_study_cards(): void
    {
        $card = $this->cardFor($this->signIn());

        $card->delete();

        $this->deleteJson("/api/study/cards/{$card->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_is_idempotent_for_study_cards_cascade_deleted_with_their_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();

        $deck->delete();

        $this->deleteJson("/api/study/cards/{$card->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_hides_missing_cross_user_and_hard_deleted_study_cards(): void
    {
        $this->signIn();
        $otherUserCard = Card::factory()->create();
        $otherUserSoftDeletedCard = Card::factory()->create();
        $hardDeletedCard = Card::factory()->create();
        $hardDeletedCardId = $hardDeletedCard->id;

        $otherUserSoftDeletedCard->delete();
        $hardDeletedCard->forceDelete();

        $this->deleteJson('/api/study/cards/'.strtolower((string) Str::ulid()))
            ->assertNotFound();
        $this->deleteJson("/api/study/cards/{$otherUserCard->id}")
            ->assertNotFound();
        $this->deleteJson("/api/study/cards/{$otherUserSoftDeletedCard->id}")
            ->assertNotFound();
        $this->deleteJson("/api/study/cards/{$hardDeletedCardId}")
            ->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $otherUserCard->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $this->deleteJson("/api/study/cards/{$card->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deleted_at' => null,
        ]);
    }
}
