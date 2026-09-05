<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use Illuminate\Support\Carbon;

class ReviewCardStateActionTest extends ReviewCardActionTestCase
{
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
}
