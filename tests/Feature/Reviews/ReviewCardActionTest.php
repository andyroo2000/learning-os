<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ReviewCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_card_review_event(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertTrue(Str::isUlid($reviewEvent->id));
        $this->assertSame(CardReviewRating::Good, $reviewEvent->rating);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $reviewedAt,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame('reviews', $entry->domain);
        $this->assertSame('card_review_event', $entry->resource_type);
        $this->assertSame($reviewEvent->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame([
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $reviewEvent->reviewed_at?->toJSON(),
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'created_at' => $reviewEvent->created_at?->toJSON(),
            'updated_at' => $reviewEvent->updated_at?->toJSON(),
        ], $entry->payload);
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
        $this->assertDatabaseCount('sync_feed_entries', 1);
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
        $this->assertDatabaseCount('sync_feed_entries', 2);
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
        $this->assertDatabaseCount('sync_feed_entries', 2);
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
