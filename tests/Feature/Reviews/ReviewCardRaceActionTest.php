<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;

class ReviewCardRaceActionTest extends ReviewCardActionTestCase
{
    public function test_it_returns_retryable_conflict_when_race_winner_disappears_before_refetch(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $raceState = (object) [
            'inserted' => false,
            'deleted' => false,
        ];
        $reviewCard = new class(app(RecordSyncFeedEntryAction::class), $raceState) extends ReviewCardAction
        {
            public function __construct(
                RecordSyncFeedEntryAction $recordSyncFeedEntry,
                private readonly object $raceState,
            ) {
                parent::__construct($recordSyncFeedEntry);
            }

            protected function saveReviewEvent(CardReviewEvent $reviewEvent): void
            {
                $this->raceState->inserted = true;

                $previous = new PDOException('UNIQUE constraint failed: card_review_events.id', 19);
                $previous->errorInfo = ['23000', '19', 'UNIQUE constraint failed: card_review_events.id'];

                throw new QueryException(
                    'sqlite',
                    'insert into "card_review_events" ("id") values (?)',
                    [$reviewEvent->id],
                    $previous,
                );
            }

            protected function findExistingReviewEventById(string $id): ?CardReviewEvent
            {
                if ($this->raceState->inserted && ! $this->raceState->deleted) {
                    $this->raceState->deleted = true;

                    return null;
                }

                return parent::findExistingReviewEventById($id);
            }
        };

        try {
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    id: $id,
                ),
            );

            $this->fail('Expected retryable review event ID conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($raceState->inserted);
            $this->assertTrue($raceState->deleted);
            $this->assertFalse($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertTrue($exception->isRetryable());
            $this->assertSame('card_review_event_retry', $exception->reason());
        }
    }
}
