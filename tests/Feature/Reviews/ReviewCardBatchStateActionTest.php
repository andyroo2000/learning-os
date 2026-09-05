<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;

class ReviewCardBatchStateActionTest extends ReviewCardBatchActionTestCase
{
    public function test_created_batch_reviews_update_card_study_state_in_review_order(): void
    {
        $card = Card::factory()->create();

        app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'event-2',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:20:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Again->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-1',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:15:00Z',
            ),
        ]);

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-05-27T09:15:00.000000Z', $card->introduced_at?->toJSON());
        $this->assertSame('2026-05-28T09:20:00.000000Z', $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame('2026-05-27T09:20:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }

    public function test_created_batch_reviews_snapshot_scheduler_state_in_review_order(): void
    {
        $card = Card::factory()->create();

        app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'event-2',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:20:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Again->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-1',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:15:00Z',
            ),
        ]);

        $firstReview = CardReviewEvent::query()
            ->where('client_event_id', 'event-1')
            ->sole();
        $secondReview = CardReviewEvent::query()
            ->where('client_event_id', 'event-2')
            ->sole();

        $this->assertFirstReviewSnapshot($firstReview);
        $this->assertSecondReviewSnapshot($firstReview, $secondReview);
    }

    private function assertFirstReviewSnapshot(CardReviewEvent $firstReview): void
    {
        $this->assertSame([
            'study_status' => 'new',
            'new_queue_position' => null,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ], $firstReview->card_state_before);
        $this->assertNull($firstReview->scheduler_state_before);
        $this->assertSame([
            'due' => '2026-05-27T09:16:00.000000Z',
            'stability' => 0.212,
            'difficulty' => 6.4133,
            'elapsed_days' => 0,
            'scheduled_days' => 0,
            'learning_steps' => 0,
            'reps' => 1,
            'lapses' => 0,
            'state' => 1,
            'last_review' => '2026-05-27T09:15:00.000000Z',
        ], $firstReview->scheduler_state_after);
    }

    private function assertSecondReviewSnapshot(
        CardReviewEvent $firstReview,
        CardReviewEvent $secondReview,
    ): void {
        $this->assertSame([
            'study_status' => 'learning',
            'new_queue_position' => null,
            'scheduler_state' => $firstReview->scheduler_state_after,
            'due_at' => '2026-05-27T09:16:00.000000Z',
            'introduced_at' => '2026-05-27T09:15:00.000000Z',
            'failed_at' => '2026-05-27T09:15:00.000000Z',
            'last_reviewed_at' => '2026-05-27T09:15:00.000000Z',
        ], $secondReview->card_state_before);
        $this->assertSame($firstReview->scheduler_state_after, $secondReview->scheduler_state_before);
        $this->assertSame([
            'due' => '2026-05-28T09:20:00.000000Z',
            'stability' => 0.42437996,
            'difficulty' => 5.20002037,
            'elapsed_days' => 0,
            'scheduled_days' => 1,
            'learning_steps' => 0,
            'reps' => 2,
            'lapses' => 0,
            'state' => 2,
            'last_review' => '2026-05-27T09:20:00.000000Z',
        ], $secondReview->scheduler_state_after);
    }
}
