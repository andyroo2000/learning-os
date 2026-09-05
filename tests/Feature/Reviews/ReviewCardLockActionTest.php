<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Support\Facades\DB;

class ReviewCardLockActionTest extends ReviewCardActionTestCase
{
    public function test_it_locks_and_reloads_the_card_before_rejecting_a_stale_single_review(): void
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

        $reviewCard = new class(app(RecordSyncFeedEntryAction::class), $concurrentState) extends ReviewCardAction
        {
            public int $lockTransactionLevel = 0;

            /** @param array<string, mixed> $concurrentState */
            public function __construct(
                RecordSyncFeedEntryAction $recordSyncFeedEntry,
                private readonly array $concurrentState,
            ) {
                parent::__construct($recordSyncFeedEntry);
            }

            protected function findCardForUpdate(string $cardId): ?Card
            {
                $this->lockTransactionLevel = DB::transactionLevel();

                // Simulate database state changing after the preflight read but before the locked reload.
                Card::query()->whereKey($cardId)->update($this->concurrentState);

                return parent::findCardForUpdate($cardId);
            }
        };

        $transactionLevelBeforeReview = DB::transactionLevel();
        try {
            $reviewCard->handle(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Again->value,
                reviewedAt: '2026-05-27T09:15:00Z',
            ));

            $this->fail('Expected stale review conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertSame($transactionLevelBeforeReview + 1, $reviewCard->lockTransactionLevel);
        $this->assertNull($card->refresh()->last_reviewed_at);
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
