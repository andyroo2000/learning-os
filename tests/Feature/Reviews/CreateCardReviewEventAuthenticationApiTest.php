<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;

class CreateCardReviewEventAuthenticationApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
