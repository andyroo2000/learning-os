<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Results\ReviewCardBatchResult;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;

class ReviewCardBatchActionTest extends ReviewCardBatchActionTestCase
{
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

        $this->assertRetriedResults($firstResult, $secondResult);
        $this->assertReviewEventSyncEntries($firstResult, $firstCard, $secondCard);
        $this->assertCardSyncEntries($firstCard, $secondCard);
    }

    private function assertRetriedResults(
        ReviewCardBatchResult $firstResult,
        ReviewCardBatchResult $secondResult,
    ): void {
        $this->assertTrue($firstResult->hasCreatedEvents);
        $this->assertFalse($secondResult->hasCreatedEvents);
        $this->assertSame($firstResult->reviewEvents->pluck('id')->all(), $secondResult->reviewEvents->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $secondResult->reviewEvents[0]->rating);
        $this->assertSame(CardReviewRating::Easy, $secondResult->reviewEvents[1]->rating);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }

    private function assertReviewEventSyncEntries(
        ReviewCardBatchResult $result,
        Card $firstCard,
        Card $secondCard,
    ): void {
        $firstEntry = SyncFeedEntry::query()
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $result->reviewEvents[0]->id)
            ->sole();
        $secondEntry = SyncFeedEntry::query()
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $result->reviewEvents[1]->id)
            ->sole();

        $this->assertSame($firstCard->ownerUserId(), $firstEntry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $firstEntry->domain);
        $this->assertSame(CardReviewEventSyncPayload::RESOURCE_TYPE, $firstEntry->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $firstEntry->operation);
        $this->assertEquals(CardReviewEventSyncPayload::fromReviewEvent($result->reviewEvents[0]), $firstEntry->payload);

        $this->assertSame($secondCard->ownerUserId(), $secondEntry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $secondEntry->domain);
        $this->assertSame(CardReviewEventSyncPayload::RESOURCE_TYPE, $secondEntry->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $secondEntry->operation);
        $this->assertEquals(CardReviewEventSyncPayload::fromReviewEvent($result->reviewEvents[1]), $secondEntry->payload);
    }

    private function assertCardSyncEntries(Card $firstCard, Card $secondCard): void
    {
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
}
