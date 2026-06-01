<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteDeckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_an_owned_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->deleteJson("/api/decks/{$deck->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('decks', [
            'id' => $deck->id,
        ]);
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
    }

    public function test_it_is_idempotent_for_an_already_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $deck->delete();
            $originalDeckDeletedAt = $deck->refresh()->deleted_at;
            $originalCardDeletedAt = $card->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $response = $this->deleteJson("/api/decks/{$deck->id}");

            $response->assertNoContent();

            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'deleted_at' => $originalDeckDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => $originalCardDeletedAt?->toDateTimeString(),
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_preserves_independently_deleted_card_timestamps(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $independentlyDeletedCard = Card::factory()->for($deck)->create();
        $activeCard = Card::factory()->for($deck)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $independentlyDeletedCard->delete();
            $originalDeletedAt = $independentlyDeletedCard->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $response = $this->deleteJson("/api/decks/{$deck->id}");

            $response->assertNoContent();

            $this->assertSoftDeleted('cards', [
                'id' => $activeCard->id,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $independentlyDeletedCard->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_hides_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $response = $this->deleteJson("/api/decks/{$otherDeck->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('decks', [
            'id' => $otherDeck->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_hides_another_users_soft_deleted_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $otherDeck->delete();

        $response = $this->deleteJson("/api/decks/{$otherDeck->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_deck(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/decks/'.((string) Str::ulid()));

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_deck_id(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/decks/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $deck = Deck::factory()->create();

        $response = $this->deleteJson("/api/decks/{$deck->id}");

        $response->assertUnauthorized();

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_requires_authentication_for_a_soft_deleted_deck(): void
    {
        $deck = Deck::factory()->create();

        $deck->delete();

        $response = $this->deleteJson("/api/decks/{$deck->id}");

        $response->assertUnauthorized();
    }
}
