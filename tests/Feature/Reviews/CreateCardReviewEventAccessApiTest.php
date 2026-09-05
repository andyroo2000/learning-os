<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Support\Str;

class CreateCardReviewEventAccessApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_rejects_missing_card(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => strtolower((string) Str::ulid()),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $otherCard->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
