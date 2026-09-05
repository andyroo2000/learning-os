<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use PHPUnit\Framework\Attributes\DataProvider;

class CreateCardReviewEventSyncConflictApiTest extends CreateCardReviewEventApiTestCase
{
    #[DataProvider('syncPayloadMismatchProvider')]
    public function test_it_rejects_sync_metadata_retries_with_a_different_payload(string $field, mixed $value): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $payload = [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'duration_ms' => 1250,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $this->postJson('/api/card-review-events', $payload)->assertCreated();

        $response = $this->postJson('/api/card-review-events', [
            ...$payload,
            $field => $value,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_reusing_sync_metadata_for_another_card_owned_by_the_same_user(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = Card::factory()->for($firstCard->deck)->create();
        $payload = [
            'card_id' => $firstCard->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $this->postJson('/api/card-review-events', $payload)->assertCreated();

        $this->postJson('/api/card-review-events', [
            ...$payload,
            'card_id' => $secondCard->id,
        ])->assertConflict();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_returns_an_actionable_conflict_for_out_of_order_reviews_without_writing_sync_state(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:20:00Z',
        ])->assertCreated();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Again->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Review events must be submitted after the card\'s latest review, ordered by reviewed_at and id.',
            )
            ->assertJsonPath('reason', 'card_review_event_out_of_order');
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public static function syncPayloadMismatchProvider(): array
    {
        return [
            'rating' => ['rating', CardReviewRating::Easy->value],
            'reviewed at' => ['reviewed_at', '2026-05-27T09:20:00Z'],
            'duration' => ['duration_ms', 1251],
            'client created at' => ['client_created_at', '2026-05-27T09:19:00Z'],
        ];
    }
}
