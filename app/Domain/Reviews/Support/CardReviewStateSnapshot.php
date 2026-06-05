<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Captures the whole card scheduling state needed for future undo flows.
 *
 * The nested scheduler_state intentionally duplicates scheduler_state_before on
 * new review events; clients keep the legacy top-level field while undo logic
 * can restore every card field from one richer snapshot.
 */
final class CardReviewStateSnapshot
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function beforeReview(Card $card): array
    {
        // Date fields use Eloquent casts for stable JSON formatting; study_status is read raw below for validation.
        return [
            'study_status' => self::studyStatusValue($card),
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => is_array($card->scheduler_state) ? $card->scheduler_state : null,
            'due_at' => $card->due_at?->toJSON(),
            'introduced_at' => $card->introduced_at?->toJSON(),
            'failed_at' => $card->failed_at?->toJSON(),
            'last_reviewed_at' => $card->last_reviewed_at?->toJSON(),
        ];
    }

    private static function studyStatusValue(Card $card): string
    {
        // Raw attributes are usually database strings; direct in-memory assignment can leave enum instances.
        $studyStatus = $card->getAttributes()['study_status'] ?? null;

        if ($studyStatus instanceof CardStudyStatus) {
            return $studyStatus->value;
        }

        // New cards default to "new"; tolerate legacy rows that predate that model default.
        if ($studyStatus === null || $studyStatus === '') {
            return CardStudyStatus::New->value;
        }

        if (is_string($studyStatus)) {
            $status = CardStudyStatus::tryFrom($studyStatus);

            if ($status !== null) {
                return $status->value;
            }

            Log::warning('Card review snapshot preserved an unrecognized study status.', [
                'card_id' => $card->getKey(),
                'study_status' => $studyStatus,
            ]);

            return $studyStatus;
        }

        throw new UnexpectedValueException('Card study status cannot be snapshotted because it is not recognized.');
    }
}
