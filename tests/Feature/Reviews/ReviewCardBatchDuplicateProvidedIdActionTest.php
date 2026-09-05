<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Support\Str;

class ReviewCardBatchDuplicateProvidedIdActionTest extends ReviewCardBatchActionTestCase
{
    public function test_it_normalizes_same_batch_duplicate_provided_id_case(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: $id,
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame([$id, $id], $result->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_uses_provided_ulid_payload_for_mixed_duplicate_client_events(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = app(ReviewCardBatchAction::class)->handle([
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
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame([$id, $id], $result->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_uses_first_payload_for_duplicate_client_events_without_provided_ids(): void
    {
        $card = Card::factory()->create();

        $result = app(ReviewCardBatchAction::class)->handle([
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
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame($result->reviewEvents[0]->id, $result->reviewEvents[1]->id);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }
}
