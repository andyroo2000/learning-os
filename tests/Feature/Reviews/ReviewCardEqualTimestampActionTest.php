<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;

class ReviewCardEqualTimestampActionTest extends ReviewCardActionTestCase
{
    public function test_equal_timestamp_reviews_use_the_event_id_as_the_chronology_tiebreaker(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = '2026-05-27T09:15:00Z';
        $firstId = '01k1j8j9m0e4k7r2y8p5w6q3aa';
        $laterId = '01k1j8j9m0e4k7r2y8p5w6q3ab';

        $first = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: $reviewedAt,
            id: $firstId,
        ))->reviewEvent;
        $later = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: $reviewedAt,
            id: $laterId,
        ))->reviewEvent;

        $card->refresh();

        $this->assertSame(2, $card->scheduler_state['reps']);
        $this->assertSame($first->scheduler_state_after, $later->scheduler_state_before);
        $this->assertSame($first->scheduler_state_after, $later->card_state_before['scheduler_state']);
        $this->assertSame('2026-05-27T09:15:00.000000Z', $later->card_state_before['last_reviewed_at']);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'again',
                reviewedAt: $reviewedAt,
                id: '01k1j8j9m0e4k7r2y8p5w6q399',
            ));

            $this->fail('Expected equal-timestamp lower-ID review conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }

    public function test_equal_timestamp_reviews_with_distinct_sync_identities_advance_an_established_sync_lineage(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = '2026-05-27T09:15:00Z';

        $first = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: $reviewedAt,
            clientEventId: 'event-first',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ))->reviewEvent;
        $later = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: $reviewedAt,
            clientEventId: 'event-later',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:01Z',
        ))->reviewEvent;

        $this->assertGreaterThan($first->id, $later->id);
        $this->assertSame(2, $card->refresh()->scheduler_state['reps']);
        $this->assertSame($first->scheduler_state_after, $later->scheduler_state_before);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }
}
