<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReviewCardCreationActionTest extends ReviewCardActionTestCase
{
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

        $this->assertReviewEventRecorded($result, $card, $reviewedAt);
        $this->assertCardSyncRecorded($card);
    }

    private function assertReviewEventRecorded(
        ReviewCardResult $result,
        Card $card,
        Carbon $reviewedAt,
    ): void {
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
    }

    private function assertCardSyncRecorded(Card $card): void
    {
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
}
