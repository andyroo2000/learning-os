<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;

final class CardReviewStateSnapshot
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function beforeReview(Card $card): array
    {
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
        $studyStatus = $card->getAttributes()['study_status'] ?? null;

        if ($studyStatus instanceof CardStudyStatus) {
            return $studyStatus->value;
        }

        if ($studyStatus === null || $studyStatus === '') {
            return CardStudyStatus::New->value;
        }

        return (string) $studyStatus;
    }
}
