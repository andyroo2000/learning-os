<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Str;

class ReviewCardBatchProvidedIdActionTest extends ReviewCardBatchActionTestCase
{
    public function test_it_returns_existing_events_for_retried_client_events_with_matching_provided_ids(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertFalse($result->hasCreatedEvents);
        $this->assertSame($id, $result->reviewEvents->sole()->id);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents->sole()->rating);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_a_legacy_uppercase_review_event_for_a_canonical_batch_retry(): void
    {
        $card = Card::factory()->create();
        $storedId = strtoupper((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $storedId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: strtolower($storedId),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertFalse($result->hasCreatedEvents);
        $this->assertSame($storedId, $result->reviewEvents->sole()->id);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_retried_client_events_with_different_provided_ids(): void
    {
        $card = Card::factory()->create();
        $firstId = strtolower((string) Str::ulid());
        $secondId = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $firstId,
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
                    id: $secondId,
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event ID conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertFalse($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }

        $this->assertDatabaseMissing('card_review_events', ['id' => $secondId]);
    }
}
