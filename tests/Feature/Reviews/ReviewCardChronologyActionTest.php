<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReviewCardChronologyActionTest extends ReviewCardActionTestCase
{
    public function test_again_reviews_mark_cards_relearning_and_failed(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => [
                'due' => '2026-05-27T09:15:00.000000Z',
                'stability' => 10,
                'difficulty' => 5,
                'elapsed_days' => 10,
                'scheduled_days' => 10,
                'learning_steps' => 0,
                'reps' => 4,
                'lapses' => 0,
                'state' => 2,
                'last_review' => '2026-05-17T09:15:00.000000Z',
            ],
        ]);
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'again',
                reviewedAt: $reviewedAt,
            ),
        );

        $card->refresh();

        $this->assertSame(CardStudyStatus::Relearning, $card->study_status);
        $this->assertSame($reviewedAt->copy()->addMinutes(10)->toJSON(), $card->due_at?->toJSON());
        $this->assertSame($reviewedAt->toJSON(), $card->failed_at?->toJSON());
        $this->assertSame($reviewedAt->toJSON(), $card->last_reviewed_at?->toJSON());
    }

    public function test_retried_existing_reviews_do_not_update_card_study_state_again(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-10T09:15:00Z',
            'introduced_at' => '2026-05-20T09:15:00Z',
            'variant_status' => VocabVariantStatus::Locked->value,
            // Existing events win as idempotent retries even when legacy card state has advanced past them.
            'last_reviewed_at' => '2026-05-28T09:15:00Z',
        ]);
        $id = strtolower((string) Str::ulid());
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => $reviewedAt,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
                id: strtoupper($id),
            ),
        );

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-06-10T09:15:00.000000Z', $card->due_at?->toJSON());
        $this->assertSame('2026-05-28T09:15:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_older_reviews_before_persistence_or_sync(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-10T09:15:00Z',
            'introduced_at' => '2026-05-20T09:15:00Z',
            'last_reviewed_at' => '2026-05-28T09:15:00Z',
        ]);

        try {
            $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'again',
                    reviewedAt: '2026-05-27T09:15:00Z',
                ),
            );

            $this->fail('Expected out-of-order review conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
            $this->assertSame(
                'Review events must be submitted after the card\'s latest review, ordered by reviewed_at and id.',
                $exception->getMessage(),
            );
        }

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-06-10T09:15:00.000000Z', $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame('2026-05-28T09:15:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
