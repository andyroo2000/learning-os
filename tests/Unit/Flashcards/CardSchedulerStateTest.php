<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSchedulerState;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CardSchedulerStateTest extends TestCase
{
    public function test_fresh_new_state_matches_client_scheduler_shape(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');

        $this->assertSame([
            'due' => '2026-06-04T12:00:00.000000Z',
            'stability' => 0.1,
            'difficulty' => 5,
            'elapsed_days' => 0,
            'scheduled_days' => 0,
            'learning_steps' => 0,
            'reps' => 0,
            'lapses' => 0,
            'state' => 0,
            'last_review' => null,
        ], CardSchedulerState::freshNew($now));
    }

    public function test_review_state_preserves_existing_difficulty_and_counts_review_attempts(): void
    {
        $card = new Card;
        $card->scheduler_state = [
            'due' => '2026-06-01T09:15:00.000000Z',
            'stability' => 10,
            'difficulty' => 4,
            'elapsed_days' => 0,
            'scheduled_days' => 3,
            'learning_steps' => 0,
            'reps' => 2,
            'lapses' => 1,
            'state' => 2,
            'last_review' => '2026-06-01T09:15:00.000000Z',
        ];

        $state = CardSchedulerState::reviewed(
            card: $card,
            rating: CardReviewRating::Again,
            studyStatus: CardStudyStatus::Relearning,
            dueAt: Carbon::parse('2026-06-04T12:10:00Z'),
            reviewedAt: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertSame([
            'due' => '2026-06-04T12:10:00.000000Z',
            'stability' => 10,
            'difficulty' => 4,
            'elapsed_days' => 3,
            'scheduled_days' => 0,
            'learning_steps' => 0,
            'reps' => 3,
            'lapses' => 2,
            'state' => 3,
            'last_review' => '2026-06-04T12:00:00.000000Z',
        ], $state);
    }

    public function test_due_override_preserves_scheduled_days_when_due_is_unchanged(): void
    {
        $card = new Card;
        $card->scheduler_state = [
            'due' => '2026-06-05T14:15:00.000000Z',
            'stability' => 10,
            'difficulty' => 4,
            'elapsed_days' => 2,
            'scheduled_days' => 5,
            'learning_steps' => 0,
            'reps' => 3,
            'lapses' => 1,
            'state' => 2,
            'last_review' => '2026-06-01T09:15:00.000000Z',
        ];

        $state = CardSchedulerState::dueOverride(
            card: $card,
            studyStatus: CardStudyStatus::Review,
            dueAt: Carbon::parse('2026-06-05T14:15:00Z'),
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertSame(5, $state['scheduled_days']);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $state['due']);
        $this->assertSame('2026-06-01T09:15:00.000000Z', $state['last_review']);
    }

    public function test_due_override_recomputes_scheduled_days_when_existing_due_is_invalid(): void
    {
        $card = new Card;
        $card->scheduler_state = [
            'due' => '2026-02-31T14:15:00.000000Z',
            'scheduled_days' => 9,
        ];

        $state = CardSchedulerState::dueOverride(
            card: $card,
            studyStatus: CardStudyStatus::Review,
            dueAt: Carbon::parse('2026-06-05T14:15:00Z'),
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertSame(1, $state['scheduled_days']);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $state['due']);
    }

    public function test_review_state_ignores_invalid_last_review_timestamps(): void
    {
        $card = new Card;
        $card->scheduler_state = [
            'last_review' => '2026-06-01T09:15:00+15:00',
            'reps' => 2,
        ];

        $state = CardSchedulerState::reviewed(
            card: $card,
            rating: CardReviewRating::Good,
            studyStatus: CardStudyStatus::Review,
            dueAt: Carbon::parse('2026-06-05T14:15:00Z'),
            reviewedAt: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertSame(0, $state['elapsed_days']);
        $this->assertSame(3, $state['reps']);
        $this->assertSame('2026-06-04T12:00:00.000000Z', $state['last_review']);
    }

    public function test_it_restores_study_status_from_scheduler_state(): void
    {
        $card = new Card;

        foreach ([
            0 => CardStudyStatus::New,
            1 => CardStudyStatus::Learning,
            2 => CardStudyStatus::Review,
            3 => CardStudyStatus::Relearning,
        ] as $state => $studyStatus) {
            $card->scheduler_state = ['state' => $state];

            $this->assertSame($studyStatus, CardSchedulerState::studyStatus($card));
        }
    }

    public function test_it_uses_the_fallback_status_when_scheduler_state_is_missing_or_unknown(): void
    {
        $card = new Card;
        $card->study_status = CardStudyStatus::Suspended;

        $this->assertSame(
            CardStudyStatus::Review,
            CardSchedulerState::studyStatus($card, CardStudyStatus::Review),
        );

        $card->scheduler_state = ['state' => 99];

        $this->assertSame(
            CardStudyStatus::Learning,
            CardSchedulerState::studyStatus($card, CardStudyStatus::Learning),
        );
    }
}
