<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UndoCardReviewEventActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_the_card_snapshot_and_deletes_the_latest_review_event(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Learning,
            'new_queue_position' => 4,
            'scheduler_state' => [
                'state' => 1,
                'reps' => 2,
            ],
            'due_at' => '2026-05-28T09:15:00Z',
            'introduced_at' => '2026-05-20T09:15:00Z',
            'failed_at' => '2026-05-24T09:15:00Z',
            'last_reviewed_at' => '2026-05-25T09:15:00Z',
        ]);

        $reviewEvent = $this->reviewCard($card, reviewedAt: '2026-05-27T09:15:00Z')->reviewEvent;

        $restoredCard = app(UndoCardReviewEventAction::class)->handle($reviewEvent);

        $this->assertSame($card->id, $restoredCard->id);
        $this->assertSame(CardStudyStatus::Learning, $restoredCard->study_status);
        $this->assertSame(4, $restoredCard->new_queue_position);
        $this->assertSame([
            'state' => 1,
            'reps' => 2,
        ], $restoredCard->scheduler_state);
        $this->assertSame('2026-05-28T09:15:00.000000Z', $restoredCard->due_at?->toJSON());
        $this->assertSame('2026-05-20T09:15:00.000000Z', $restoredCard->introduced_at?->toJSON());
        $this->assertSame('2026-05-24T09:15:00.000000Z', $restoredCard->failed_at?->toJSON());
        $this->assertSame('2026-05-25T09:15:00.000000Z', $restoredCard->last_reviewed_at?->toJSON());
        $this->assertDatabaseMissing('card_review_events', ['id' => $reviewEvent->id]);

        $deleteEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card_review_event')
            ->where('operation', SyncFeedOperation::Delete->value)
            ->sole();

        $this->assertSame($reviewEvent->id, $deleteEntry->resource_id);
        $this->assertSame($reviewEvent->id, $deleteEntry->payload['id']);

        $latestCardEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card')
            ->latest('checkpoint')
            ->firstOrFail();

        $this->assertSame(SyncFeedOperation::Update, $latestCardEntry->operation);
        $this->assertSame('learning', $latestCardEntry->payload['study_status']);
        $this->assertSame(4, $latestCardEntry->payload['new_queue_position']);
    }

    public function test_it_rejects_undoing_a_review_that_is_not_the_latest_for_the_card(): void
    {
        $card = Card::factory()->create();
        $firstReviewEvent = $this->reviewCard($card, reviewedAt: '2026-05-27T09:15:00Z')->reviewEvent;
        $this->reviewCard($card->refresh(), reviewedAt: '2026-05-27T09:20:00Z');

        try {
            app(UndoCardReviewEventAction::class)->handle($firstReviewEvent);

            $this->fail('Expected latest-review undo conflict was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_not_latest', $exception->reason());
            $this->assertSame(409, $exception->statusCode());
        }

        $this->assertDatabaseHas('card_review_events', ['id' => $firstReviewEvent->id]);
    }

    public function test_it_uses_review_event_id_as_the_latest_tiebreaker_for_equal_review_times(): void
    {
        $card = Card::factory()->create();
        $snapshot = [
            'study_status' => 'new',
            'new_queue_position' => null,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ];
        $firstReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3aa',
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'card_state_before' => $snapshot,
        ]);
        CardReviewEvent::factory()->for($card)->create([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3ab',
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'card_state_before' => $snapshot,
        ]);

        try {
            app(UndoCardReviewEventAction::class)->handle($firstReviewEvent);

            $this->fail('Expected latest-review undo conflict was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_not_latest', $exception->reason());
        }
    }

    public function test_it_rejects_review_events_without_a_card_state_snapshot(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create([
            'card_state_before' => null,
        ]);

        try {
            app(UndoCardReviewEventAction::class)->handle($reviewEvent);

            $this->fail('Expected missing undo snapshot exception was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_missing_undo_state', $exception->reason());
            $this->assertSame(422, $exception->statusCode());
        }

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    private function reviewCard(Card $card, string $reviewedAt): ReviewCardResult
    {
        return app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
            ),
        );
    }
}
