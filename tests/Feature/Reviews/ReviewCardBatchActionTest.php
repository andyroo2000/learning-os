<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ApplyCardStudyReviewAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ReviewCardBatchActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_events_for_retried_client_events(): void
    {
        $course = Course::factory()->create();
        $deck = Deck::factory()->create([
            'user_id' => $course->user_id,
            'course_id' => $course->id,
        ]);
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->create();

        $items = [
            ReviewCardData::fromInput(
                cardId: $firstCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $secondCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'event-456',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:19:00Z',
            ),
        ];

        $firstResult = app(ReviewCardBatchAction::class)->handle($items);
        $secondResult = app(ReviewCardBatchAction::class)->handle($items);

        $this->assertTrue($firstResult->hasCreatedEvents);
        $this->assertFalse($secondResult->hasCreatedEvents);
        $this->assertSame($firstResult->reviewEvents->pluck('id')->all(), $secondResult->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $secondResult->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Easy, $secondResult->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);

        $firstEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card_review_event')
            ->where('resource_id', $firstResult->reviewEvents[0]->id)
            ->sole();
        $secondEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card_review_event')
            ->where('resource_id', $firstResult->reviewEvents[1]->id)
            ->sole();

        $this->assertSame($firstCard->ownerUserId(), $firstEntry->user_id);
        $this->assertSame('reviews', $firstEntry->domain);
        $this->assertSame('card_review_event', $firstEntry->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $firstEntry->operation);
        $this->assertSame([
            'id' => $firstResult->reviewEvents[0]->id,
            'card_id' => $firstCard->id,
            'deck_id' => $deck->id,
            'course_id' => $course->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => $firstResult->reviewEvents[0]->reviewed_at?->toJSON(),
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => $firstResult->reviewEvents[0]->client_created_at?->toJSON(),
            'scheduler_state_before' => null,
            'scheduler_state_after' => [
                'due' => '2026-05-30T09:15:00.000000Z',
                'stability' => 0.1,
                'difficulty' => 5,
                'elapsed_days' => 0,
                'scheduled_days' => 3,
                'learning_steps' => 0,
                'reps' => 1,
                'lapses' => 0,
                'state' => 2,
                'last_review' => '2026-05-27T09:15:00.000000Z',
            ],
            'created_at' => $firstResult->reviewEvents[0]->created_at?->toJSON(),
            'updated_at' => $firstResult->reviewEvents[0]->updated_at?->toJSON(),
        ], $firstEntry->payload);
        $this->assertSame($secondCard->ownerUserId(), $secondEntry->user_id);

        $this->assertDatabaseHas('sync_feed_entries', [
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => $firstCard->id,
            'operation' => 'update',
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => $secondCard->id,
            'operation' => 'update',
        ]);
    }

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
        $this->assertSame('2026-06-03T09:20:00.000000Z', $card->due_at?->toJSON());
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

        $this->assertNull($firstReview->scheduler_state_before);
        $this->assertSame([
            'due' => '2026-05-27T09:25:00.000000Z',
            'stability' => 0.1,
            'difficulty' => 5,
            'elapsed_days' => 0,
            'scheduled_days' => 0,
            'learning_steps' => 0,
            'reps' => 1,
            'lapses' => 1,
            'state' => 3,
            'last_review' => '2026-05-27T09:15:00.000000Z',
        ], $firstReview->scheduler_state_after);
        $this->assertSame($firstReview->scheduler_state_after, $secondReview->scheduler_state_before);
        $this->assertSame([
            'due' => '2026-06-03T09:20:00.000000Z',
            'stability' => 0.1,
            'difficulty' => 5,
            'elapsed_days' => 0,
            'scheduled_days' => 7,
            'learning_steps' => 0,
            'reps' => 2,
            'lapses' => 1,
            'state' => 2,
            'last_review' => '2026-05-27T09:20:00.000000Z',
        ], $secondReview->scheduler_state_after);
    }

    public function test_it_normalizes_text_and_sync_metadata_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: strtoupper($card->id),
                rating: '  good  ',
                reviewedAt: '  2026-05-27T09:15:00Z  ',
                clientEventId: '  event-123  ',
                deviceId: '  device-abc  ',
                clientCreatedAt: '  2026-05-27T09:14:00Z  ',
            ),
        ]);
        $reviewEvent = $result->reviewEvents->sole();

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Good, $reviewEvent->rating);
        $this->assertSame('event-123', $reviewEvent->client_event_id);
        $this->assertSame('device-abc', $reviewEvent->device_id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_returns_existing_events_for_retried_client_events_with_matching_provided_ids(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:19:00Z',
            ),
        ]);

        $this->assertFalse($result->hasCreatedEvents);
        $this->assertSame($id, $result->reviewEvents->sole()->id);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents->sole()->rating);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_retried_client_events_with_different_provided_ids(): void
    {
        $card = Card::factory()->create();
        $firstId = strtolower((string) Str::ulid());
        $secondId = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $firstId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    id: $secondId,
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event ID conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertFalse($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }

        $this->assertDatabaseMissing('card_review_events', ['id' => $secondId]);
    }

    public function test_it_normalizes_same_batch_duplicate_provided_id_case(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                id: $id,
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:19:00Z',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame([$id, $id], $result->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_uses_provided_ulid_payload_for_mixed_duplicate_client_events(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:19:00Z',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame([$id, $id], $result->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Easy, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Easy, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_uses_first_payload_for_duplicate_client_events_without_provided_ids(): void
    {
        $card = Card::factory()->create();

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:19:00Z',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame($result->reviewEvents[0]->id, $result->reviewEvents[1]->id);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_reports_cross_user_sync_metadata_collisions_for_http_hiding(): void
    {
        $card = Card::factory()->create();
        $otherCard = Card::factory()->create();
        CardReviewEvent::factory()->for($otherCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event ownership conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $card = Card::factory()->create();
        $reviewCards = new ReviewCardBatchAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
            applyCardStudyReview: app(ApplyCardStudyReviewAction::class),
        );

        try {
            $reviewCards->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseCount('card_review_events', 0);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rejects_missing_cards_without_creating_review_events(): void
    {
        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: strtolower((string) Str::ulid()),
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected missing card validation to reject the batch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('One or more cards do not exist.', $exception->getMessage());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
