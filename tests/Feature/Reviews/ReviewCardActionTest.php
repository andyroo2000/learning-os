<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ApplyCardStudyReviewAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ReviewCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_card_review_event(): void
    {
        $course = Course::factory()->create();
        $deck = Deck::factory()->create([
            'user_id' => $course->user_id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
                durationMs: 1250,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertTrue(Str::isUlid($reviewEvent->id));
        $this->assertSame(CardReviewRating::Good, $reviewEvent->rating);
        $this->assertSame([
            'study_status' => 'new',
            'new_queue_position' => null,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ], $reviewEvent->card_state_before);
        $this->assertNull($reviewEvent->scheduler_state_before);
        $this->assertSame([
            'due' => $reviewedAt->copy()->addDays(3)->toJSON(),
            'stability' => 0.1,
            'difficulty' => 5,
            'elapsed_days' => 0,
            'scheduled_days' => 3,
            'learning_steps' => 0,
            'reps' => 1,
            'lapses' => 0,
            'state' => 2,
            'last_review' => $reviewedAt->toJSON(),
        ], $reviewEvent->scheduler_state_after);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $reviewedAt,
            'duration_ms' => 1250,
        ]);

        $entry = SyncFeedEntry::query()
            ->where('resource_type', 'card_review_event')
            ->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame('reviews', $entry->domain);
        $this->assertSame('card_review_event', $entry->resource_type);
        $this->assertSame($reviewEvent->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame([
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'deck_id' => $deck->id,
            'course_id' => $course->id,
            'rating' => 'good',
            'reviewed_at' => $reviewEvent->reviewed_at?->toJSON(),
            'duration_ms' => 1250,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'card_state_before' => [
                'study_status' => 'new',
                'new_queue_position' => null,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ],
            'scheduler_state_before' => null,
            'scheduler_state_after' => [
                'due' => $reviewedAt->copy()->addDays(3)->toJSON(),
                'stability' => 0.1,
                'difficulty' => 5,
                'elapsed_days' => 0,
                'scheduled_days' => 3,
                'learning_steps' => 0,
                'reps' => 1,
                'lapses' => 0,
                'state' => 2,
                'last_review' => $reviewedAt->toJSON(),
            ],
            'created_at' => $reviewEvent->created_at?->toJSON(),
            'updated_at' => $reviewEvent->updated_at?->toJSON(),
        ], $entry->payload);

        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card')
            ->sole();

        $card->refresh();

        $this->assertSame($card->ownerUserId(), $cardEntry->user_id);
        $this->assertSame('flashcards', $cardEntry->domain);
        $this->assertSame('card', $cardEntry->resource_type);
        $this->assertSame($card->id, $cardEntry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $cardEntry->operation);
        $this->assertSame('review', $cardEntry->payload['study_status']);
        $this->assertNull($cardEntry->payload['new_queue_position']);
        $this->assertSame($reviewedAt->toJSON(), $cardEntry->payload['introduced_at']);
        $this->assertSame($reviewedAt->toJSON(), $cardEntry->payload['last_reviewed_at']);
    }

    public function test_created_reviews_update_card_study_state(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
            ),
        );

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame($reviewedAt->toJSON(), $card->introduced_at?->toJSON());
        $this->assertSame($reviewedAt->copy()->addDays(3)->toJSON(), $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame($reviewedAt->toJSON(), $card->last_reviewed_at?->toJSON());
    }

    public function test_again_reviews_mark_cards_relearning_and_failed(): void
    {
        $card = Card::factory()->create();
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
            'last_reviewed_at' => '2026-05-26T09:15:00Z',
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
        $this->assertSame('2026-05-26T09:15:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_older_reviews_do_not_move_card_study_state_backwards(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-10T09:15:00Z',
            'introduced_at' => '2026-05-20T09:15:00Z',
            'last_reviewed_at' => '2026-05-28T09:15:00Z',
        ]);

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'again',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-06-10T09:15:00.000000Z', $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame('2026-05-28T09:15:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertDatabaseHas('sync_feed_entries', [
            'resource_type' => 'card_review_event',
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27 09:15:00',
                id: $id,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($id, $reviewEvent->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $card->ownerUserId(),
            'domain' => 'reviews',
            'resource_type' => 'card_review_event',
            'resource_id' => $id,
            'operation' => 'create',
        ]);
    }

    public function test_it_returns_existing_review_event_for_provided_ulid_retries(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');
        $existingReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => $reviewedAt,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
                id: strtoupper($id),
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingReviewEvent->is($result->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_existing_review_event_for_provided_ulid_retries_with_sync_metadata(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');
        $clientCreatedAt = Carbon::parse('2026-05-27 09:14:00');
        $existingReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => $reviewedAt,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => $clientCreatedAt,
        ]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
                id: $id,
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: $clientCreatedAt,
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingReviewEvent->is($result->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_provided_ulid_retries_with_different_metadata(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        $this->expectException(CardReviewEventConflictException::class);
        $this->expectExceptionMessage('Card review event ID already exists with different metadata.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27 09:15:00',
                id: $id,
            ),
        );
    }

    public function test_it_reports_cross_user_provided_ulid_conflicts_for_http_hiding(): void
    {
        $card = Card::factory()->create();
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        try {
            $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27 09:15:00',
                    id: $id,
                ),
            );

            $this->fail('Expected review event ID conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }
    }

    public function test_it_reports_cross_user_provided_ulid_conflicts_for_soft_deleted_cards(): void
    {
        $card = Card::factory()->create();
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $otherCard->delete();

        try {
            $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27 09:15:00',
                    id: $id,
                ),
            );

            $this->fail('Expected review event ID conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }
    }

    public function test_it_returns_retryable_conflict_when_race_winner_disappears_before_refetch(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $raceState = (object) [
            'inserted' => false,
            'deleted' => false,
        ];
        $reviewCard = new class(app(RecordSyncFeedEntryAction::class), app(ApplyCardStudyReviewAction::class), $raceState) extends ReviewCardAction
        {
            public function __construct(
                RecordSyncFeedEntryAction $recordSyncFeedEntry,
                ApplyCardStudyReviewAction $applyCardStudyReview,
                private readonly object $raceState,
            ) {
                parent::__construct($recordSyncFeedEntry, $applyCardStudyReview);
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
                    reviewedAt: '2026-05-27 09:15:00',
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

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $reviewCard = new ReviewCardAction(
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
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'easy',
                    reviewedAt: '2026-05-27 09:15:00',
                    id: $id,
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('card_review_events', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rolls_back_when_card_study_state_sync_recording_fails(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $recordSyncFeedEntry = new class extends RecordSyncFeedEntryAction
        {
            public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
            {
                if ($data->resourceType === 'card') {
                    throw new RuntimeException('Card sync feed failed.');
                }

                return parent::handle($data);
            }
        };
        $reviewCard = new ReviewCardAction(
            recordSyncFeedEntry: $recordSyncFeedEntry,
            applyCardStudyReview: new ApplyCardStudyReviewAction($recordSyncFeedEntry),
        );

        try {
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'easy',
                    reviewedAt: '2026-05-27 09:15:00',
                    id: $id,
                ),
            );

            $this->fail('Expected card sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Card sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('card_review_events', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
            $this->assertSame(CardStudyStatus::New, $card->refresh()->study_status);
            $this->assertNull($card->last_reviewed_at);
        }
    }

    public function test_it_stores_client_sync_metadata(): void
    {
        $card = Card::factory()->create();
        $clientCreatedAt = Carbon::parse('2026-05-27 09:14:00');

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: $clientCreatedAt,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame('event-123', $reviewEvent->client_event_id);
        $this->assertSame('device-abc', $reviewEvent->device_id);
        $this->assertTrue($clientCreatedAt->equalTo($reviewEvent->client_created_at));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_normalizes_text_and_sync_metadata_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtoupper($card->id),
                rating: '  good  ',
                reviewedAt: '  2026-05-27 09:15:00  ',
                clientEventId: '  event-123  ',
                deviceId: '  device-abc  ',
                clientCreatedAt: '  2026-05-27 09:14:00  ',
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
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

    public function test_it_is_idempotent_for_the_same_client_event_and_device(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27 09:14:00',
            ),
        );

        $secondResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27 09:20:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27 09:19:00',
            ),
        );
        $firstReviewEvent = $firstResult->reviewEvent;
        $secondReviewEvent = $secondResult->reviewEvent;

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertTrue($firstReviewEvent->is($secondReviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $firstReviewEvent->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_creates_distinct_events_for_retries_without_sync_metadata(): void
    {
        $card = Card::factory()->create();
        $data = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
        );

        $firstResult = $this->reviewCard($data);
        $secondResult = $this->reviewCard($data);

        $this->assertTrue($firstResult->wasCreated);
        $this->assertTrue($secondResult->wasCreated);
        $this->assertFalse($firstResult->reviewEvent->is($secondResult->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 3);
    }

    public function test_it_creates_a_distinct_event_when_a_retry_adds_sync_metadata(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        $secondResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
        );

        $firstReviewEvent = $firstResult->reviewEvent->refresh();
        $secondReviewEvent = $secondResult->reviewEvent;

        $this->assertTrue($firstResult->wasCreated);
        $this->assertTrue($secondResult->wasCreated);
        $this->assertFalse($firstReviewEvent->is($secondReviewEvent));
        $this->assertNull($firstReviewEvent->client_event_id);
        $this->assertNull($firstReviewEvent->device_id);
        $this->assertNull($firstReviewEvent->client_created_at);
        $this->assertSame('event-123', $secondReviewEvent->client_event_id);
        $this->assertSame('device-abc', $secondReviewEvent->device_id);
        $this->assertSame('2026-05-27 09:14:00', $secondReviewEvent->client_created_at->toDateTimeString());
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 3);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = Card::factory()->create();

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: "  {$card->id}  ",
                rating: '  hard  ',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Hard, $reviewEvent->rating);
    }

    public function test_it_accepts_each_supported_rating(): void
    {
        $card = Card::factory()->create();

        foreach (CardReviewRating::cases() as $rating) {
            $result = $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: $rating->value,
                    reviewedAt: '2026-05-27 09:15:00',
                ),
            );
            $reviewEvent = $result->reviewEvent;

            $this->assertTrue($result->wasCreated);
            $this->assertSame($rating, $reviewEvent->rating);
        }
    }

    public function test_it_rejects_invalid_card_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: 'not-a-ulid',
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_missing_card(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card does not exist.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtolower((string) Str::ulid()),
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_blank_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating is required.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: '   ',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_unsupported_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating must be one of: again, hard, good, easy.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'medium',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_invalid_duration_ms(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review duration_ms must be a non-negative integer.');

        ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27 09:15:00',
            durationMs: '-1',
        );
    }

    public function test_it_rejects_partial_client_sync_metadata(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client event ID, device ID, and client created at must be provided together.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                clientEventId: 'event-123',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review event ID must be a valid ULID.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                id: 'not-a-ulid',
            ),
        );
    }

    private function reviewCard(ReviewCardData $data): ReviewCardResult
    {
        return app(ReviewCardAction::class)->handle($data);
    }
}
