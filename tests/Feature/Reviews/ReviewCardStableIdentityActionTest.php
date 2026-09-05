<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;

class ReviewCardStableIdentityActionTest extends ReviewCardActionTestCase
{
    public function test_it_rejects_an_equal_timestamp_resubmission_without_a_stable_identity(): void
    {
        $card = Card::factory()->create();
        $data = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
        );

        $firstResult = $this->reviewCard($data);

        try {
            $this->reviewCard($data);

            $this->fail('Expected equal-timestamp identity conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_identity_required', $exception->reason());
            $this->assertSame(
                'Equal-timestamp review events require an explicit id or complete sync metadata.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($firstResult->wasCreated);
        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_rejects_equal_timestamp_sync_metadata_when_the_existing_event_has_no_sync_identity(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ));

            $this->fail('Expected equal-timestamp identity conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_identity_required', $exception->reason());
        }

        $firstReviewEvent = $firstResult->reviewEvent->refresh();

        $this->assertTrue($firstResult->wasCreated);
        $this->assertNull($firstReviewEvent->client_event_id);
        $this->assertNull($firstReviewEvent->device_id);
        $this->assertNull($firstReviewEvent->client_created_at);
        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }
}
