<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\AdvanceCardProgressionAfterReviewAction;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\DateTime\StrictIsoDateTime;
use Illuminate\Support\Carbon;

final class CardReviewStateRestorer
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function restore(Card $card, array $snapshot): void
    {
        $preserveProgressionRetirement = AdvanceCardProgressionAfterReviewAction::supports($card)
            && $card->variant_status === VocabVariantStatus::Locked->value
            && $card->study_status === CardStudyStatus::Suspended;

        // Keep this restore list in sync with CardReviewStateSnapshot::beforeReview().
        $card->study_status = self::studyStatus($snapshot);
        if ($preserveProgressionRetirement) {
            $card->study_status = CardStudyStatus::Suspended;
        }
        $card->new_queue_position = self::nullableInteger($snapshot, 'new_queue_position');
        if (! $card->isProgressionAvailable()) {
            $card->new_queue_position = null;
        }
        $card->scheduler_state = self::nullableArray($snapshot, 'scheduler_state');
        $card->due_at = self::nullableTimestamp($snapshot, 'due_at');
        $card->introduced_at = self::nullableTimestamp($snapshot, 'introduced_at');
        $card->failed_at = self::nullableTimestamp($snapshot, 'failed_at');
        $card->setLastReviewedAt(self::nullableTimestamp($snapshot, 'last_reviewed_at'));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function studyStatus(array $snapshot): CardStudyStatus
    {
        $studyStatus = $snapshot['study_status'] ?? null;

        if (! is_string($studyStatus)) {
            throw UndoCardReviewEventException::invalidSnapshot('study_status');
        }

        return CardStudyStatus::tryFrom($studyStatus)
            ?? throw UndoCardReviewEventException::invalidSnapshot('study_status');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function nullableInteger(array $snapshot, string $key): ?int
    {
        $value = self::nullableValue($snapshot, $key);

        if ($value !== null && ! is_int($value)) {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private static function nullableArray(array $snapshot, string $key): ?array
    {
        $value = self::nullableValue($snapshot, $key);

        if ($value !== null && ! is_array($value)) {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function nullableTimestamp(array $snapshot, string $key): ?Carbon
    {
        $value = self::nullableValue($snapshot, $key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        return StrictIsoDateTime::parseOrNull($value)
            ?? throw UndoCardReviewEventException::invalidSnapshot($key);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function nullableValue(array $snapshot, string $key): mixed
    {
        return $snapshot[$key] ?? null;
    }
}
