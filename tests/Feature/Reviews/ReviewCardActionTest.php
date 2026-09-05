<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;

class ReviewCardActionTest extends ReviewCardActionTestCase
{
    public function test_it_rejects_new_review_events_for_progression_locked_cards(): void
    {
        $card = Card::factory()->create([
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
            ));
            $this->fail('Expected progression-locked review conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_progression_locked', $exception->reason());
            $this->assertFalse($exception->isRetryable());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertSame(CardStudyStatus::New, $card->refresh()->study_status);
        $this->assertNull($card->due_at);
        $this->assertNull($card->scheduler_state);
    }
}
