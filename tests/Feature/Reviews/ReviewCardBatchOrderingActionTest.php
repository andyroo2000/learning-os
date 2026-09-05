<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReviewCardBatchOrderingActionTest extends ReviewCardBatchActionTestCase
{
    public function test_it_locks_and_reloads_batch_cards_before_rejecting_late_reviews(): void
    {
        $card = Card::factory()->create();
        $concurrentState = [
            'study_status' => CardStudyStatus::Review->value,
            'scheduler_state' => [
                'state' => 2,
                'reps' => 8,
                'stability' => 12.5,
            ],
            'due_at' => '2026-06-10T09:15:00Z',
            'introduced_at' => '2026-05-20T09:15:00Z',
            'last_reviewed_at' => '2026-05-28T09:15:00Z',
        ];

        $reviewCards = new class(app(RecordSyncFeedEntryAction::class), $concurrentState) extends ReviewCardBatchAction
        {
            public int $lockTransactionLevel = 0;

            /** @param array<string, mixed> $concurrentState */
            public function __construct(
                RecordSyncFeedEntryAction $recordSyncFeedEntry,
                private readonly array $concurrentState,
            ) {
                parent::__construct($recordSyncFeedEntry);
            }

            protected function cardsById(Collection $preparedItems): Collection
            {
                $this->lockTransactionLevel = DB::transactionLevel();
                $cardId = $preparedItems->pluck('card_id')->first();

                Card::query()->whereKey($cardId)->update($this->concurrentState);

                return parent::cardsById($preparedItems);
            }
        };

        $transactionLevelBeforeReview = DB::transactionLevel();
        try {
            $reviewCards->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Again->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected out-of-order batch review conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertSame($transactionLevelBeforeReview + 1, $reviewCards->lockTransactionLevel);
        $this->assertNull($card->refresh()->last_reviewed_at);
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_equal_timestamp_batch_reviews_are_applied_by_event_id_not_input_order(): void
    {
        $card = Card::factory()->create();

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                id: '01k1j8j9m0e4k7r2y8p5w6q3ab',
                clientEventId: 'event-later',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:15:01Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Again->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                id: '01k1j8j9m0e4k7r2y8p5w6q3aa',
                clientEventId: 'event-first',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:15:00Z',
            ),
        ]);

        $first = CardReviewEvent::query()->findOrFail('01k1j8j9m0e4k7r2y8p5w6q3aa');
        $later = CardReviewEvent::query()->findOrFail('01k1j8j9m0e4k7r2y8p5w6q3ab');

        $this->assertSame([
            '01k1j8j9m0e4k7r2y8p5w6q3ab',
            '01k1j8j9m0e4k7r2y8p5w6q3aa',
        ], $result->reviewEvents->pluck('id')->all());
        $this->assertSame(2, $card->refresh()->scheduler_state['reps']);
        $this->assertSame($first->scheduler_state_after, $later->scheduler_state_before);
        $this->assertSame($first->scheduler_state_after, $later->card_state_before['scheduler_state']);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }

    public function test_an_out_of_order_item_rejects_the_whole_batch_before_persistence_or_sync(): void
    {
        $reviewedCard = Card::factory()->create();
        $otherCard = Card::factory()->create();

        app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $reviewedCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'existing-event',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:20:00Z',
            ),
        ]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $otherCard->id,
                    rating: CardReviewRating::Easy->value,
                    reviewedAt: '2026-05-27T09:25:00Z',
                    clientEventId: 'otherwise-valid-event',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:25:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $reviewedCard->id,
                    rating: CardReviewRating::Again->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'late-event',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:15:00Z',
                ),
            ]);

            $this->fail('Expected out-of-order batch conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseMissing('card_review_events', ['card_id' => $otherCard->id]);
        $this->assertDatabaseCount('sync_feed_entries', 2);
        $this->assertNull($otherCard->refresh()->last_reviewed_at);
    }

    public function test_a_sync_identified_equal_time_item_cannot_extend_an_anonymous_lineage_and_rejects_the_whole_batch(): void
    {
        $anonymousCard = Card::factory()->create();
        $otherCard = Card::factory()->create();
        $reviewedAt = '2026-05-27T09:15:00Z';

        app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $anonymousCard->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: $reviewedAt,
        ));

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $otherCard->id,
                    rating: CardReviewRating::Easy->value,
                    reviewedAt: '2026-05-27T09:20:00Z',
                    clientEventId: 'otherwise-valid-event',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:19:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $anonymousCard->id,
                    rating: CardReviewRating::Again->value,
                    reviewedAt: $reviewedAt,
                    clientEventId: 'late-identity-event',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected equal-timestamp identity conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_identity_required', $exception->reason());
        }

        $this->assertSame(1, $anonymousCard->refresh()->scheduler_state['reps']);
        $this->assertNull($otherCard->refresh()->last_reviewed_at);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_batch_response_order_does_not_follow_canonical_lock_order(): void
    {
        $firstCard = Card::factory()->create(['id' => '01k00000000000000000000001']);
        $secondCard = Card::factory()->create(['id' => '01k00000000000000000000002']);

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $secondCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'event-2',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:20:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $firstCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-1',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:15:00Z',
            ),
        ]);

        $this->assertSame([
            $secondCard->id,
            $firstCard->id,
        ], $result->reviewEvents->pluck('card_id')->all());
    }
}
