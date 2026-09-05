<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReviewCardNormalizationActionTest extends ReviewCardActionTestCase
{
    public function test_it_stores_client_sync_metadata(): void
    {
        $card = Card::factory()->create();
        $clientCreatedAt = Carbon::parse('2026-05-27 09:14:00');

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: $clientCreatedAt,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame('event-123', $reviewEvent->client_event_id);
        $this->assertSame('device-abc', $reviewEvent->device_id);
        $this->assertTrue($clientCreatedAt->equalTo($reviewEvent->client_created_at));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_normalizes_text_and_sync_metadata_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtoupper($card->id),
                rating: '  good  ',
                reviewedAt: '  2026-05-27T09:15:00Z  ',
                clientEventId: '  event-123  ',
                deviceId: '  device-abc  ',
                clientCreatedAt: '  2026-05-27T09:14:00Z  ',
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Good, $reviewEvent->rating);
        $this->assertSame('event-123', $reviewEvent->client_event_id);
        $this->assertSame('device-abc', $reviewEvent->device_id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_reviews_cards_from_legacy_imports_with_uppercase_ulids(): void
    {
        $storedCardId = strtoupper((string) Str::ulid());
        $card = Card::factory()->create(['id' => $storedCardId]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtolower($storedCardId),
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        $this->assertTrue($result->wasCreated);
        $this->assertSame($storedCardId, $result->reviewEvent->card_id);
        $this->assertSame($storedCardId, CardReviewEvent::query()->findOrFail($result->reviewEvent->id)->card_id);
        $this->assertSame(CardStudyStatus::Learning, $card->refresh()->study_status);
    }
}
