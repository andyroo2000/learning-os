<?php

namespace Tests\Unit\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Support\CardReviewStateSnapshot;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use UnexpectedValueException;

class CardReviewStateSnapshotTest extends TestCase
{
    public function test_it_snapshots_valid_raw_string_study_status_values(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'study_status' => CardStudyStatus::Review->value,
        ], sync: true);

        $snapshot = CardReviewStateSnapshot::beforeReview($card);

        $this->assertSame('review', $snapshot['study_status']);
    }

    public function test_it_snapshots_in_memory_enum_study_status_values(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'study_status' => CardStudyStatus::Learning,
            'new_queue_position' => 3,
        ], sync: true);

        $snapshot = CardReviewStateSnapshot::beforeReview($card);

        $this->assertSame([
            'study_status' => 'learning',
            'new_queue_position' => 3,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ], $snapshot);
    }

    public function test_it_defaults_missing_raw_study_status_to_new(): void
    {
        $card = new Card;
        $card->setRawAttributes([], sync: true);

        $snapshot = CardReviewStateSnapshot::beforeReview($card);

        $this->assertSame('new', $snapshot['study_status']);
    }

    public function test_it_logs_and_preserves_unrecognized_raw_string_study_status_values(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'study_status' => 'archived',
        ], sync: true);

        Log::shouldReceive('warning')
            ->once()
            ->with('Card review snapshot preserved an unrecognized study status.', [
                'card_id' => null,
                'study_status' => 'archived',
            ]);

        $snapshot = CardReviewStateSnapshot::beforeReview($card);

        $this->assertSame('archived', $snapshot['study_status']);
    }

    public function test_it_rejects_non_string_raw_study_status_values(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'study_status' => ['archived'],
        ], sync: true);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Card study status cannot be snapshotted because it is not recognized.');

        CardReviewStateSnapshot::beforeReview($card);
    }
}
