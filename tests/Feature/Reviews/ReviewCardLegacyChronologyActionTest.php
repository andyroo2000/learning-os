<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;

class ReviewCardLegacyChronologyActionTest extends ReviewCardActionTestCase
{
    public function test_it_rejects_an_equal_card_timestamp_when_no_persisted_event_establishes_the_id_tiebreaker(): void
    {
        $card = Card::factory()->create([
            'last_reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                id: '01k1j8j9m0e4k7r2y8p5w6q3ab',
            ));

            $this->fail('Expected unknown equal-timestamp chronology conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertSame('2026-05-27T09:15:00.000000Z', $card->refresh()->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_legacy_mixed_case_ids_use_normalized_order_when_finding_the_latest_equal_timestamp_event(): void
    {
        $reviewedAt = '2026-05-27T09:15:00Z';
        $card = Card::factory()->create(['last_reviewed_at' => $reviewedAt]);
        CardReviewEvent::factory()->for($card)->create([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3aa',
            'reviewed_at' => $reviewedAt,
        ]);
        CardReviewEvent::factory()->for($card)->create([
            'id' => '01K1J8J9M0E4K7R2Y8P5W6Q3AC',
            'reviewed_at' => $reviewedAt,
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
                id: '01k1j8j9m0e4k7r2y8p5w6q3ab',
            ));

            $this->fail('Expected normalized legacy ID chronology conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
