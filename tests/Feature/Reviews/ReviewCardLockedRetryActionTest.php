<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Support\Str;

class ReviewCardLockedRetryActionTest extends ReviewCardActionTestCase
{
    public function test_it_rechecks_exact_retry_identity_after_waiting_for_the_card_lock(): void
    {
        $card = Card::factory()->create();
        $reviewEventId = strtolower((string) Str::ulid());
        $reviewCard = new class(app(RecordSyncFeedEntryAction::class), $card, $reviewEventId) extends ReviewCardAction
        {
            public function __construct(
                RecordSyncFeedEntryAction $recordSyncFeedEntry,
                private readonly Card $card,
                private readonly string $reviewEventId,
            ) {
                parent::__construct($recordSyncFeedEntry);
            }

            protected function findCardForUpdate(string $cardId): ?Card
            {
                // Simulate a retry winner becoming visible after the outer pre-check but before the locked re-check.
                CardReviewEvent::factory()->for($this->card)->create([
                    'id' => $this->reviewEventId,
                    'rating' => CardReviewRating::Good,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ]);

                return parent::findCardForUpdate($cardId);
            }
        };

        $result = $reviewCard->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00Z',
            id: $reviewEventId,
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));

        $this->assertFalse($result->wasCreated);
        $this->assertSame($reviewEventId, $result->reviewEvent->id);
        $this->assertSame(CardStudyStatus::New, $card->refresh()->study_status);
        $this->assertNull($card->last_reviewed_at);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_provided_ulid_retries_with_different_metadata(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        $this->expectException(CardReviewEventConflictException::class);
        $this->expectExceptionMessage('Card review event ID already exists with different metadata.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                id: $id,
            ),
        );
    }
}
