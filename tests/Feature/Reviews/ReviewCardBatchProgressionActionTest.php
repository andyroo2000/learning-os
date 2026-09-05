<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;

class ReviewCardBatchProgressionActionTest extends ReviewCardBatchActionTestCase
{
    public function test_it_rejects_new_batch_events_for_progression_locked_cards_atomically(): void
    {
        $availableCard = Card::factory()->create();
        $lockedCard = Card::factory()->create([
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $availableCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-available',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $lockedCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:16:00Z',
                    clientEventId: 'event-locked',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:15:00Z',
                ),
            ]);
            $this->fail('Expected progression-locked batch conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_progression_locked', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertNull($availableCard->refresh()->scheduler_state);
        $this->assertNull($lockedCard->refresh()->scheduler_state);
    }
}
