<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;

class ReviewCardIdempotencyActionTest extends ReviewCardActionTestCase
{
    public function test_it_is_idempotent_for_the_same_canonical_payload(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
        );

        $secondResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtoupper($card->id),
                rating: 'good',
                reviewedAt: '2026-05-27T05:15:00-04:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        );
        $firstReviewEvent = $firstResult->reviewEvent;
        $secondReviewEvent = $secondResult->reviewEvent;

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertTrue($firstReviewEvent->is($secondReviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $firstReviewEvent->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_rejects_a_sync_metadata_retry_with_different_payload(): void
    {
        $card = Card::factory()->create();

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));

        $this->expectException(CardReviewEventConflictException::class);
        $this->expectExceptionMessage('Card review event ID already exists with different metadata.');

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));
    }

    public function test_it_rejects_reusing_sync_metadata_for_another_card_with_the_same_owner(): void
    {
        $deck = Deck::factory()->create();
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $firstCard->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));

        $this->expectException(CardReviewEventConflictException::class);
        $this->expectExceptionMessage('Card review event ID already exists with different metadata.');

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $secondCard->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));
    }
}
