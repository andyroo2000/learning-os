<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;

class ReviewCardBatchSyncConflictActionTest extends ReviewCardBatchActionTestCase
{
    public function test_it_rejects_duplicate_sync_keys_with_different_payloads_before_writing(): void
    {
        $card = Card::factory()->create();

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Easy->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event payload conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertFalse($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_reusing_sync_metadata_for_another_card_with_the_same_owner(): void
    {
        $deck = Deck::factory()->create();
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $firstReview = ReviewCardData::fromInput(
            cardId: $firstCard->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        );

        app(ReviewCardBatchAction::class)->handle([$firstReview]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $secondCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event card conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertFalse($exception->shouldBeHiddenFrom($firstCard->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_reports_cross_user_sync_metadata_collisions_for_http_hiding(): void
    {
        $card = Card::factory()->create();
        $otherCard = Card::factory()->create();
        CardReviewEvent::factory()->for($otherCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event ownership conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }
    }
}
