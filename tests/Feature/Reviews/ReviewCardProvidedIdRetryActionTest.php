<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReviewCardProvidedIdRetryActionTest extends ReviewCardActionTestCase
{
    public function test_it_returns_existing_review_event_for_provided_ulid_retries(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');
        $existingReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => $reviewedAt,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
                id: strtoupper($id),
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingReviewEvent->is($result->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_a_legacy_uppercase_review_event_for_a_canonical_provided_id_retry(): void
    {
        $card = Card::factory()->create();
        $storedId = strtoupper((string) Str::ulid());
        $existingReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => $storedId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $result = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T05:15:00-04:00',
            id: strtolower($storedId),
        ));

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingReviewEvent->is($result->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_returns_existing_review_event_for_provided_ulid_retries_with_sync_metadata(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');
        $clientCreatedAt = Carbon::parse('2026-05-27 09:14:00');
        $existingReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => $reviewedAt,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => $clientCreatedAt,
        ]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
                id: $id,
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: $clientCreatedAt,
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingReviewEvent->is($result->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
