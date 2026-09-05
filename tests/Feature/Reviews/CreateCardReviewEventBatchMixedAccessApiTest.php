<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardReviewEventBatchMixedAccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_only_the_card_in_a_soft_deleted_deck_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $validCard = $this->cardFor($user);
        $deck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $validCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $deletedDeckCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_soft_deleted_card_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $validCard = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);

        $deletedCard->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $validCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $deletedCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_unowned_card_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $ownedCard = $this->cardFor($user);
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $ownedCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $otherCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
