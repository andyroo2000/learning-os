<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            'due' => $reviewedAt->copy()->addMinutes(10)->toJSON(),
            'stability' => 2.3065,
            'difficulty' => 2.11810397,
            'elapsed_days' => 0,
            'scheduled_days' => 0,
            'learning_steps' => 1,
            'reps' => 1,
            'lapses' => 0,
            'state' => 1,
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
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $reviewEvent->id)
            ->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CardReviewEventSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame($reviewEvent->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame(CardReviewEventSyncPayload::fromReviewEvent($reviewEvent), $entry->payload);

        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();

        $card->refresh()->load('deck');

        $this->assertSame($card->ownerUserId(), $cardEntry->user_id);
        $this->assertSame(CardSyncPayload::DOMAIN, $cardEntry->domain);
        $this->assertSame(CardSyncPayload::RESOURCE_TYPE, $cardEntry->resource_type);
        $this->assertSame($card->id, $cardEntry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $cardEntry->operation);
        $this->assertSame(CardSyncPayload::fromCard($card), $cardEntry->payload);
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

        $this->assertSame(CardStudyStatus::Learning, $card->study_status);
        $this->assertSame($reviewedAt->toJSON(), $card->introduced_at?->toJSON());
        $this->assertSame($reviewedAt->copy()->addMinutes(10)->toJSON(), $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame($reviewedAt->toJSON(), $card->last_reviewed_at?->toJSON());
    }

    public function test_it_snapshots_existing_card_state_before_single_reviews(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Learning,
            'new_queue_position' => 7,
            'scheduler_state' => [
                'state' => 1,
                'reps' => 2,
            ],
            'due_at' => '2026-05-28T09:15:00Z',
            'introduced_at' => '2026-05-20T09:15:00Z',
            'failed_at' => '2026-05-24T09:15:00Z',
            'last_reviewed_at' => '2026-05-25T09:15:00Z',
        ]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        $this->assertSame([
            'study_status' => 'learning',
            'new_queue_position' => 7,
            'scheduler_state' => [
                'state' => 1,
                'reps' => 2,
            ],
            'due_at' => '2026-05-28T09:15:00.000000Z',
            'introduced_at' => '2026-05-20T09:15:00.000000Z',
            'failed_at' => '2026-05-24T09:15:00.000000Z',
            'last_reviewed_at' => '2026-05-25T09:15:00.000000Z',
        ], $result->reviewEvent->card_state_before);
    }

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

    public function test_equal_timestamp_reviews_use_the_event_id_as_the_chronology_tiebreaker(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = '2026-05-27T09:15:00Z';
        $firstId = '01k1j8j9m0e4k7r2y8p5w6q3aa';
        $laterId = '01k1j8j9m0e4k7r2y8p5w6q3ab';

        $first = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: $reviewedAt,
            id: $firstId,
        ))->reviewEvent;
        $later = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: $reviewedAt,
            id: $laterId,
        ))->reviewEvent;

        $card->refresh();

        $this->assertSame(2, $card->scheduler_state['reps']);
        $this->assertSame($first->scheduler_state_after, $later->scheduler_state_before);
        $this->assertSame($first->scheduler_state_after, $later->card_state_before['scheduler_state']);
        $this->assertSame('2026-05-27T09:15:00.000000Z', $later->card_state_before['last_reviewed_at']);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'again',
                reviewedAt: $reviewedAt,
                id: '01k1j8j9m0e4k7r2y8p5w6q399',
            ));

            $this->fail('Expected equal-timestamp lower-ID review conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }

    public function test_equal_timestamp_reviews_with_distinct_sync_identities_advance_an_established_sync_lineage(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = '2026-05-27T09:15:00Z';

        $first = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: $reviewedAt,
            clientEventId: 'event-first',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ))->reviewEvent;
        $later = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: $reviewedAt,
            clientEventId: 'event-later',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:01Z',
        ))->reviewEvent;

        $this->assertGreaterThan($first->id, $later->id);
        $this->assertSame(2, $card->refresh()->scheduler_state['reps']);
        $this->assertSame($first->scheduler_state_after, $later->scheduler_state_before);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }

    public function test_it_rejects_an_equal_card_timestamp_when_no_persisted_event_establishes_the_id_tiebreaker(): void
    {
        $card = Card::factory()->create([
            'last_reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                id: '01k1j8j9m0e4k7r2y8p5w6q3ab',
            ));

            $this->fail('Expected unknown equal-timestamp chronology conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertSame('2026-05-27T09:15:00.000000Z', $card->refresh()->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_legacy_mixed_case_ids_use_normalized_order_when_finding_the_latest_equal_timestamp_event(): void
    {
        $reviewedAt = '2026-05-27T09:15:00Z';
        $card = Card::factory()->create(['last_reviewed_at' => $reviewedAt]);
        CardReviewEvent::factory()->for($card)->create([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3aa',
            'reviewed_at' => $reviewedAt,
        ]);
        CardReviewEvent::factory()->for($card)->create([
            'id' => '01K1J8J9M0E4K7R2Y8P5W6Q3AC',
            'reviewed_at' => $reviewedAt,
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
                id: '01k1j8j9m0e4k7r2y8p5w6q3ab',
            ));

            $this->fail('Expected normalized legacy ID chronology conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_card_millisecond_chronology_rejects_an_older_within_second_review_without_an_event_tiebreaker(): void
    {
        $card = Card::factory()->create();
        DB::table('cards')->where('id', $card->id)->update([
            'last_reviewed_at' => '2026-05-27 09:15:00.456',
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00.123999Z',
            ));

            $this->fail('Expected within-second chronology conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertSame('2026-05-27T09:15:00.456000Z', $card->refresh()->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27T09:15:00Z',
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
        $entry = SyncFeedEntry::query()
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $id)
            ->where('operation', SyncFeedOperation::Create->value)
            ->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CardReviewEventSyncPayload::fromReviewEvent($result->reviewEvent), $entry->payload);
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

    public function test_it_returns_a_legacy_uppercase_review_event_for_a_canonical_provided_id_retry(): void
    {
        $card = Card::factory()->create();
        $storedId = strtoupper((string) Str::ulid());
        $existingReviewEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => $storedId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $result = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T05:15:00-04:00',
            id: strtolower($storedId),
        ));

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingReviewEvent->is($result->reviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
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

    public function test_it_rechecks_exact_retry_identity_after_waiting_for_the_card_lock(): void
    {
        $card = Card::factory()->create();
        $reviewEventId = strtolower((string) Str::ulid());
        $reviewCard = new class(app(RecordSyncFeedEntryAction::class), $card, $reviewEventId) extends ReviewCardAction
        {
            public function __construct(
                RecordSyncFeedEntryAction $recordSyncFeedEntry,
                private readonly Card $card,
                private readonly string $reviewEventId,
            ) {
                parent::__construct($recordSyncFeedEntry);
            }

            protected function findCardForUpdate(string $cardId): ?Card
            {
                // Simulate a retry winner becoming visible after the outer pre-check but before the locked re-check.
                CardReviewEvent::factory()->for($this->card)->create([
                    'id' => $this->reviewEventId,
                    'rating' => CardReviewRating::Good,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ]);

                return parent::findCardForUpdate($cardId);
            }
        };

        $result = $reviewCard->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00Z',
            id: $reviewEventId,
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));

        $this->assertFalse($result->wasCreated);
        $this->assertSame($reviewEventId, $result->reviewEvent->id);
        $this->assertSame(CardStudyStatus::New, $card->refresh()->study_status);
        $this->assertNull($card->last_reviewed_at);
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
                reviewedAt: '2026-05-27T09:15:00Z',
                id: $id,
            ),
        );
    }

    public function test_direct_callers_replay_sub_millisecond_review_instants_at_database_precision(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $firstData = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T05:15:00.123111-04:00',
            id: $id,
        );
        $replayData = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00.123999Z',
            id: $id,
        );

        $first = $this->reviewCard($firstData);
        $second = $this->reviewCard($replayData);

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($id, $second->reviewEvent->id);
        $this->assertSame('2026-05-27T09:15:00.123000Z', $second->reviewEvent->reviewed_at->toJSON());
        $card->refresh()->load('deck');

        $this->assertSame('2026-05-27T09:15:00.123000Z', $card->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T09:15:00.123000Z', CardResource::make($card)->resolve()['last_reviewed_at']);
        $this->assertSame(1, $card->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);

        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();

        $this->assertSame('2026-05-27T09:15:00.123000Z', $cardEntry->payload['last_reviewed_at']);
    }

    public function test_within_second_review_chronology_reaches_snapshots_resources_and_sync(): void
    {
        $card = Card::factory()->create();

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00.123999Z',
        ));
        $later = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00.456999Z',
        ));

        $card->refresh()->load('deck');
        $this->assertSame('2026-05-27T09:15:00.123000Z', $later->reviewEvent->card_state_before['last_reviewed_at']);
        $this->assertSame('2026-05-27T09:15:00.456000Z', $card->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T09:15:00.456000Z', CardResource::make($card)->resolve()['last_reviewed_at']);

        $cardEntries = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->orderBy('checkpoint')
            ->get();

        $this->assertCount(2, $cardEntries);
        $this->assertSame('2026-05-27T09:15:00.123000Z', $cardEntries[0]->payload['last_reviewed_at']);
        $this->assertSame('2026-05-27T09:15:00.456000Z', $cardEntries[1]->payload['last_reviewed_at']);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
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
                    reviewedAt: '2026-05-27T09:15:00Z',
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
                    reviewedAt: '2026-05-27T09:15:00Z',
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
        );

        try {
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'easy',
                    reviewedAt: '2026-05-27T09:15:00Z',
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
        );

        try {
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'easy',
                    reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '  2026-05-27T09:15:00Z  ',
                clientEventId: '  event-123  ',
                deviceId: '  device-abc  ',
                clientCreatedAt: '  2026-05-27T09:14:00Z  ',
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

    public function test_it_reviews_cards_from_legacy_imports_with_uppercase_ulids(): void
    {
        $storedCardId = strtoupper((string) Str::ulid());
        $card = Card::factory()->create(['id' => $storedCardId]);

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtolower($storedCardId),
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        $this->assertTrue($result->wasCreated);
        $this->assertSame($storedCardId, $result->reviewEvent->card_id);
        $this->assertSame($storedCardId, CardReviewEvent::query()->findOrFail($result->reviewEvent->id)->card_id);
        $this->assertSame(CardStudyStatus::Learning, $card->refresh()->study_status);
    }

    public function test_it_is_idempotent_for_the_same_canonical_payload(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
        );

        $secondResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtoupper($card->id),
                rating: 'good',
                reviewedAt: '2026-05-27T05:15:00-04:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
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

    public function test_it_rejects_a_sync_metadata_retry_with_different_payload(): void
    {
        $card = Card::factory()->create();

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));

        $this->expectException(CardReviewEventConflictException::class);
        $this->expectExceptionMessage('Card review event ID already exists with different metadata.');

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'easy',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));
    }

    public function test_it_rejects_reusing_sync_metadata_for_another_card_with_the_same_owner(): void
    {
        $deck = Deck::factory()->create();
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $firstCard->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));

        $this->expectException(CardReviewEventConflictException::class);
        $this->expectExceptionMessage('Card review event ID already exists with different metadata.');

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $secondCard->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        ));
    }

    public function test_it_rejects_an_equal_timestamp_resubmission_without_a_stable_identity(): void
    {
        $card = Card::factory()->create();
        $data = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
        );

        $firstResult = $this->reviewCard($data);

        try {
            $this->reviewCard($data);

            $this->fail('Expected equal-timestamp identity conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_identity_required', $exception->reason());
            $this->assertSame(
                'Equal-timestamp review events require an explicit id or complete sync metadata.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($firstResult->wasCreated);
        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_rejects_equal_timestamp_sync_metadata_when_the_existing_event_has_no_sync_identity(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ));

            $this->fail('Expected equal-timestamp identity conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_identity_required', $exception->reason());
        }

        $firstReviewEvent = $firstResult->reviewEvent->refresh();

        $this->assertTrue($firstResult->wasCreated);
        $this->assertNull($firstReviewEvent->client_event_id);
        $this->assertNull($firstReviewEvent->device_id);
        $this->assertNull($firstReviewEvent->client_created_at);
        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = Card::factory()->create();

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: "  {$card->id}  ",
                rating: '  hard  ',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Hard, $reviewEvent->rating);
    }

    public function test_it_rejects_timezone_naive_timestamp_strings_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reviewed_at must be a valid ISO-8601 datetime.');

        ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00',
        );
    }

    public function test_it_rejects_timezone_naive_client_created_timestamp_strings_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_created_at must be a valid ISO-8601 datetime.');

        ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00',
        );
    }

    public function test_it_accepts_each_supported_rating(): void
    {
        $card = Card::factory()->create();
        $ids = [
            '01k1j8j9m0e4k7r2y8p5w6q3aa',
            '01k1j8j9m0e4k7r2y8p5w6q3ab',
            '01k1j8j9m0e4k7r2y8p5w6q3ac',
            '01k1j8j9m0e4k7r2y8p5w6q3ad',
        ];

        foreach (CardReviewRating::cases() as $index => $rating) {
            $result = $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: $rating->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    id: $ids[$index],
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
                reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '2026-05-27T09:15:00Z',
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
            reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '2026-05-27T09:15:00Z',
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
                reviewedAt: '2026-05-27T09:15:00Z',
                id: 'not-a-ulid',
            ),
        );
    }

    private function reviewCard(ReviewCardData $data): ReviewCardResult
    {
        return app(ReviewCardAction::class)->handle($data);
    }
}
