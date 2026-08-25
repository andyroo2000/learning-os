<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                durationMs: 1250,
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $secondCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                durationMs: 1820,
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
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $firstResult->reviewEvents[0]->id)
            ->sole();
        $secondEntry = SyncFeedEntry::query()
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $firstResult->reviewEvents[1]->id)
            ->sole();

        $this->assertSame($firstCard->ownerUserId(), $firstEntry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $firstEntry->domain);
        $this->assertSame(CardReviewEventSyncPayload::RESOURCE_TYPE, $firstEntry->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $firstEntry->operation);
        $this->assertEquals(CardReviewEventSyncPayload::fromReviewEvent($firstResult->reviewEvents[0]), $firstEntry->payload);

        $this->assertSame($secondCard->ownerUserId(), $secondEntry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $secondEntry->domain);
        $this->assertSame(CardReviewEventSyncPayload::RESOURCE_TYPE, $secondEntry->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $secondEntry->operation);
        $this->assertEquals(CardReviewEventSyncPayload::fromReviewEvent($firstResult->reviewEvents[1]), $secondEntry->payload);

        $firstCard->refresh()->load('deck');
        $secondCard->refresh()->load('deck');

        $firstCardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $firstCard->id)
            ->where('operation', SyncFeedOperation::Update->value)
            ->sole();
        $secondCardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $secondCard->id)
            ->where('operation', SyncFeedOperation::Update->value)
            ->sole();

        $this->assertSame($firstCard->ownerUserId(), $firstCardEntry->user_id);
        $this->assertSame(CardSyncPayload::DOMAIN, $firstCardEntry->domain);
        $this->assertEquals(CardSyncPayload::fromCard($firstCard), $firstCardEntry->payload);
        $this->assertSame($secondCard->ownerUserId(), $secondCardEntry->user_id);
        $this->assertSame(CardSyncPayload::DOMAIN, $secondCardEntry->domain);
        $this->assertEquals(CardSyncPayload::fromCard($secondCard), $secondCardEntry->payload);
    }

    public function test_an_idempotent_batch_retry_returns_the_existing_event_even_if_card_state_has_advanced(): void
    {
        $card = Card::factory()->create();
        $items = [
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
        ];

        $firstResult = app(ReviewCardBatchAction::class)->handle($items);
        Card::query()->whereKey($card->id)->update([
            'last_reviewed_at' => '2026-05-27T09:20:00Z',
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $retryResult = app(ReviewCardBatchAction::class)->handle($items);

        $this->assertFalse($retryResult->hasCreatedEvents);
        $this->assertSame($firstResult->reviewEvents->sole()->id, $retryResult->reviewEvents->sole()->id);
        $this->assertSame('2026-05-27T09:20:00.000000Z', $card->refresh()->last_reviewed_at->toJSON());
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_rejects_new_batch_events_for_progression_locked_cards_atomically(): void
    {
        $availableCard = Card::factory()->create();
        $lockedCard = Card::factory()->create([
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $availableCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-available',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $lockedCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:16:00Z',
                    clientEventId: 'event-locked',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:15:00Z',
                ),
            ]);
            $this->fail('Expected progression-locked batch conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_progression_locked', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertNull($availableCard->refresh()->scheduler_state);
        $this->assertNull($lockedCard->refresh()->scheduler_state);
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

    public function test_it_reviews_cards_from_legacy_imports_with_uppercase_ulids(): void
    {
        $storedCardId = strtoupper((string) Str::ulid());
        $card = Card::factory()->create(['id' => $storedCardId]);

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: strtolower($storedCardId),
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'legacy-event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame($storedCardId, $result->reviewEvents->sole()->card_id);
        $this->assertSame(CardStudyStatus::Learning, $card->refresh()->study_status);
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
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertFalse($result->hasCreatedEvents);
        $this->assertSame($id, $result->reviewEvents->sole()->id);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents->sole()->rating);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_a_legacy_uppercase_review_event_for_a_canonical_batch_retry(): void
    {
        $card = Card::factory()->create();
        $storedId = strtoupper((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $storedId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: strtolower($storedId),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertFalse($result->hasCreatedEvents);
        $this->assertSame($storedId, $result->reviewEvents->sole()->id);
        $this->assertDatabaseCount('card_review_events', 1);
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
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: $id,
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
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
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                id: strtoupper($id),
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame([$id, $id], $result->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
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
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T05:15:00-04:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T05:14:00-04:00',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame($result->reviewEvents[0]->id, $result->reviewEvents[1]->id);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Good, $result->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_duplicate_sync_keys_with_different_payloads_before_writing(): void
    {
        $card = Card::factory()->create();

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
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Easy->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event payload conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertFalse($exception->shouldBeHiddenFrom($card->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_reusing_sync_metadata_for_another_card_with_the_same_owner(): void
    {
        $deck = Deck::factory()->create();
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $firstReview = ReviewCardData::fromInput(
            cardId: $firstCard->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00Z',
        );

        app(ReviewCardBatchAction::class)->handle([$firstReview]);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $secondCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected review event card conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertFalse($exception->shouldBeHiddenFrom($firstCard->ownerUserId()));
            $this->assertSame('card_review_event_id_conflict', $exception->reason());
        }

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
