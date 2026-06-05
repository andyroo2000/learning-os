<?php

namespace Tests\Unit\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Support\CardReviewStateSnapshot;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class CardReviewStateSnapshotTest extends TestCase
{
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

    public function test_it_rejects_unrecognized_raw_study_status_values(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'study_status' => 'archived',
        ], sync: true);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Card study status cannot be snapshotted because it is not recognized.');

        CardReviewStateSnapshot::beforeReview($card);
    }
}
