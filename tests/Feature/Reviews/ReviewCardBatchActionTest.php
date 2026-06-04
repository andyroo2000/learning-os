<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
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
        $firstCard = Card::factory()->create();
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
        $this->assertDatabaseCount('sync_feed_entries', 2);

        $firstEntry = SyncFeedEntry::query()
            ->where('resource_id', $firstResult->reviewEvents[0]->id)
            ->sole();
        $secondEntry = SyncFeedEntry::query()
            ->where('resource_id', $firstResult->reviewEvents[1]->id)
            ->sole();

        $this->assertSame($firstCard->ownerUserId(), $firstEntry->user_id);
        $this->assertSame('reviews', $firstEntry->domain);
        $this->assertSame('card_review_event', $firstEntry->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $firstEntry->operation);
        $this->assertSame([
            'id' => $firstResult->reviewEvents[0]->id,
            'card_id' => $firstCard->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => $firstResult->reviewEvents[0]->reviewed_at?->toJSON(),
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => $firstResult->reviewEvents[0]->client_created_at?->toJSON(),
            'created_at' => $firstResult->reviewEvents[0]->created_at?->toJSON(),
            'updated_at' => $firstResult->reviewEvents[0]->updated_at?->toJSON(),
        ], $firstEntry->payload);
        $this->assertSame($secondCard->ownerUserId(), $secondEntry->user_id);
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
