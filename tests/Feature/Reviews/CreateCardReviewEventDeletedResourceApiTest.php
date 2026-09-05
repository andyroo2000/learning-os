<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;

class CreateCardReviewEventDeletedResourceApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_rejects_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
