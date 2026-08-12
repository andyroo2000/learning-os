<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LockedCardStudyReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_locked_hard_review_moves_a_new_card_to_learning(): void
    {
        $card = Card::factory()->create([
            'new_queue_position' => 3,
        ]);
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');

        app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Hard->value,
            reviewedAt: $reviewedAt,
        ));

        $card->refresh();

        $this->assertSame(CardStudyStatus::Learning, $card->study_status);
        $this->assertNull($card->new_queue_position);
        $this->assertSame($reviewedAt->toJSON(), $card->introduced_at?->toJSON());
        $this->assertSame($reviewedAt->copy()->addMinutes(6)->toJSON(), $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame($reviewedAt->toJSON(), $card->last_reviewed_at?->toJSON());
        $this->assertSame([
            'due' => '2026-05-27T09:21:00.000000Z',
            'stability' => 1.2931,
            'difficulty' => 5.11217071,
            'elapsed_days' => 0,
            'scheduled_days' => 0,
            'learning_steps' => 0,
            'reps' => 1,
            'lapses' => 0,
            'state' => 1,
            'last_review' => '2026-05-27T09:15:00.000000Z',
        ], $card->scheduler_state);
        $this->assertCardSyncPayloadRecorded($card);
    }

    public function test_a_locked_hard_review_keeps_a_review_card_in_review(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'introduced_at' => '2026-05-20T09:15:00Z',
        ]);
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');

        app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Hard->value,
            reviewedAt: $reviewedAt,
        ));

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-05-20T09:15:00.000000Z', $card->introduced_at?->toJSON());
        $this->assertSame($reviewedAt->copy()->addDay()->toJSON(), $card->due_at?->toJSON());
        $this->assertSame(2, $card->scheduler_state['state']);
        $this->assertSame(1, $card->scheduler_state['scheduled_days']);
        $this->assertCardSyncPayloadRecorded($card);
    }

    private function assertCardSyncPayloadRecorded(Card $card): void
    {
        $card->load('deck');

        $entry = SyncFeedEntry::query()
            ->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->where('operation', SyncFeedOperation::Update->value)
            ->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);
    }
}
