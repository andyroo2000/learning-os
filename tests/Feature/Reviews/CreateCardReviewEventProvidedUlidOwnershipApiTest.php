<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Str;

class CreateCardReviewEventProvidedUlidOwnershipApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_hides_client_provided_ulid_conflicts_for_other_users(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_client_provided_ulid_conflicts_for_other_users_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $otherCard->delete();

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts_for_owned_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($deletedCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $deletedCard->delete();

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }
}
