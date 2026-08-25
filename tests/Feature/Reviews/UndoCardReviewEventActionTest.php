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
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AssertsCardReviewEventSyncFeedEntries;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class UndoCardReviewEventActionTest extends TestCase
{
    use AssertsCardReviewEventSyncFeedEntries;
    use AssertsCardSyncFeedEntries;
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
        $reviewCardEntry = $this->assertCardSyncPayloadRecorded(
            $card->refresh()->load('deck'),
            SyncFeedOperation::Update,
        );
        $deletedAt = Carbon::parse('2026-05-27T10:00:00Z');

        Carbon::setTestNow($deletedAt);

        try {
            $restoredCard = app(UndoCardReviewEventAction::class)->handle($reviewEvent);
        } finally {
            Carbon::setTestNow();
        }

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

        $restoredCard->refresh()->load('deck');
        $reviewEvent->setRelation('card', $restoredCard);

        $this->assertCardReviewEventDeleteSyncPayloadRecorded($reviewEvent, $deletedAt);
        $this->assertCardSyncPayloadRecorded(
            $restoredCard,
            SyncFeedOperation::Update,
            afterCheckpoint: $reviewCardEntry->checkpoint,
        );
    }

    public function test_it_does_not_restore_a_queue_position_while_the_card_is_progression_locked(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);
        $reviewEvent = CardReviewEvent::factory()->for($card)->create([
            'card_state_before' => [
                'study_status' => CardStudyStatus::New->value,
                'new_queue_position' => 7,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
        ]);

        $restoredCard = app(UndoCardReviewEventAction::class)->handle($reviewEvent);

        $this->assertSame(CardStudyStatus::New, $restoredCard->study_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $restoredCard->variant_status);
        $this->assertNull($restoredCard->new_queue_position);
        $this->assertDatabaseMissing('card_review_events', ['id' => $reviewEvent->id]);
        $this->assertNull($this->assertCardSyncPayloadRecorded(
            $restoredCard->refresh()->load('deck'),
            SyncFeedOperation::Update,
        )->payload['new_queue_position']);
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
            // Legacy uppercase rows must follow the same normalized ordering as new writes.
            'id' => '01K1J8J9M0E4K7R2Y8P5W6Q3AB',
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

    public function test_it_undoes_the_later_equal_timestamp_review_to_its_exact_prior_snapshot(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = '2026-05-27T09:15:00Z';
        $firstReviewEvent = app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'again',
            reviewedAt: $reviewedAt,
            id: '01k1j8j9m0e4k7r2y8p5w6q3aa',
        ))->reviewEvent;
        $laterReviewEvent = app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: $reviewedAt,
            id: '01k1j8j9m0e4k7r2y8p5w6q3ab',
        ))->reviewEvent;

        $this->assertSame(2, $card->refresh()->scheduler_state['reps']);

        $restoredCard = app(UndoCardReviewEventAction::class)->handle($laterReviewEvent);

        $this->assertSame($firstReviewEvent->scheduler_state_after, $restoredCard->scheduler_state);
        $this->assertSame('2026-05-27T09:15:00.000000Z', $restoredCard->last_reviewed_at?->toJSON());
        $this->assertDatabaseHas('card_review_events', ['id' => $firstReviewEvent->id]);
        $this->assertDatabaseMissing('card_review_events', ['id' => $laterReviewEvent->id]);
    }

    public function test_it_restores_exact_millisecond_last_reviewed_at_from_the_prior_snapshot(): void
    {
        $card = Card::factory()->create();
        $firstReviewEvent = $this->reviewCard($card, reviewedAt: '2026-05-27T09:15:00.123999Z')->reviewEvent;
        $firstCardUpdate = $this->assertCardSyncPayloadRecorded(
            $card->refresh()->load('deck'),
            SyncFeedOperation::Update,
        );
        $laterReviewEvent = $this->reviewCard($card->refresh(), reviewedAt: '2026-05-27T09:15:00.456999Z')->reviewEvent;
        $laterCardUpdate = $this->assertCardSyncPayloadRecorded(
            $card->refresh()->load('deck'),
            SyncFeedOperation::Update,
            afterCheckpoint: $firstCardUpdate->checkpoint,
        );

        $this->assertSame('2026-05-27T09:15:00.123000Z', $laterReviewEvent->card_state_before['last_reviewed_at']);

        $restoredCard = app(UndoCardReviewEventAction::class)->handle($laterReviewEvent);

        $this->assertSame('2026-05-27T09:15:00.123000Z', $restoredCard->last_reviewed_at?->toJSON());
        $this->assertDatabaseHas('card_review_events', ['id' => $firstReviewEvent->id]);
        $this->assertDatabaseMissing('card_review_events', ['id' => $laterReviewEvent->id]);

        $cardUpdate = $this->assertCardSyncPayloadRecorded(
            $restoredCard->refresh()->load('deck'),
            SyncFeedOperation::Update,
            afterCheckpoint: $laterCardUpdate->checkpoint,
        );

        $this->assertSame('2026-05-27T09:15:00.123000Z', $cardUpdate->payload['last_reviewed_at']);
    }

    public function test_it_rejects_review_events_for_soft_deleted_cards(): void
    {
        $card = Card::factory()->create();
        $reviewEvent = $this->reviewCard($card, reviewedAt: '2026-05-27T09:15:00Z')->reviewEvent;
        $card->delete();

        try {
            app(UndoCardReviewEventAction::class)->handle($reviewEvent);

            $this->fail('Expected unavailable card undo exception was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_card_unavailable', $exception->reason());
        }

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_rejects_review_events_for_cards_in_soft_deleted_decks(): void
    {
        $card = Card::factory()->create();
        $reviewEvent = $this->reviewCard($card, reviewedAt: '2026-05-27T09:15:00Z')->reviewEvent;
        $card->deck->delete();

        try {
            app(UndoCardReviewEventAction::class)->handle($reviewEvent);

            $this->fail('Expected unavailable card undo exception was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_card_unavailable', $exception->reason());
        }

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_rejects_stale_review_event_models_after_the_row_is_deleted(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create([
            'card_state_before' => [
                'study_status' => 'new',
                'new_queue_position' => null,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
        ]);
        $reviewEvent->delete();

        try {
            app(UndoCardReviewEventAction::class)->handle($reviewEvent);

            $this->fail('Expected unavailable review event undo exception was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_unavailable', $exception->reason());
        }

        $this->assertDatabaseCount('sync_feed_entries', 0);
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
        }

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_rejects_invalid_undo_snapshot_fields(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create([
            'card_state_before' => [
                'study_status' => 123,
                'new_queue_position' => null,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
        ]);

        try {
            app(UndoCardReviewEventAction::class)->handle($reviewEvent);

            $this->fail('Expected invalid undo snapshot exception was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_invalid_undo_state', $exception->reason());
        }
    }

    public function test_it_rejects_relative_timestamp_strings_in_undo_snapshots(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create([
            'card_state_before' => [
                'study_status' => 'new',
                'new_queue_position' => null,
                'scheduler_state' => null,
                'due_at' => 'tomorrow',
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
        ]);

        try {
            app(UndoCardReviewEventAction::class)->handle($reviewEvent);

            $this->fail('Expected invalid undo snapshot exception was not thrown.');
        } catch (UndoCardReviewEventException $exception) {
            $this->assertSame('card_review_event_invalid_undo_state', $exception->reason());
        }
    }

    public function test_it_rejects_invalid_iso_timestamp_strings_in_undo_snapshots(): void
    {
        foreach ([
            '2026-02-31T09:15:00Z',
            '2026-05-28T09:15:00+15:00',
            '2026-05-28T09:15:00-13:00',
        ] as $dueAt) {
            $reviewEvent = CardReviewEvent::factory()->create([
                'card_state_before' => [
                    'study_status' => 'new',
                    'new_queue_position' => null,
                    'scheduler_state' => null,
                    'due_at' => $dueAt,
                    'introduced_at' => null,
                    'failed_at' => null,
                    'last_reviewed_at' => null,
                ],
            ]);

            try {
                app(UndoCardReviewEventAction::class)->handle($reviewEvent);

                $this->fail('Expected invalid undo snapshot exception was not thrown.');
            } catch (UndoCardReviewEventException $exception) {
                $this->assertSame('card_review_event_invalid_undo_state', $exception->reason());
            }

            $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
        }
    }

    public function test_it_accepts_iso_timestamp_strings_without_fractional_seconds_in_undo_snapshots(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create([
            'card_state_before' => [
                'study_status' => 'review',
                'new_queue_position' => null,
                'scheduler_state' => null,
                'due_at' => '2026-05-28T09:15:00Z',
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
        ]);

        $restoredCard = app(UndoCardReviewEventAction::class)->handle($reviewEvent);

        $this->assertSame('2026-05-28T09:15:00.000000Z', $restoredCard->due_at?->toJSON());
    }

    public function test_it_normalizes_offset_timestamp_strings_in_undo_snapshots(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create([
            'card_state_before' => [
                'study_status' => 'review',
                'new_queue_position' => null,
                'scheduler_state' => null,
                'due_at' => '2026-05-28T09:15:00+05:30',
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
        ]);

        $restoredCard = app(UndoCardReviewEventAction::class)->handle($reviewEvent);

        $this->assertSame('2026-05-28T03:45:00.000000Z', $restoredCard->due_at?->toJSON());
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
