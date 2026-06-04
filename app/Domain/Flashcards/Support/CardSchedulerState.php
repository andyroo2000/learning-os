<?php

namespace App\Domain\Flashcards\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Support\Carbon;

final class CardSchedulerState
{
    private const DEFAULT_STABILITY = 0.1;

    private const DEFAULT_DIFFICULTY = 5;

    private const STATE_NEW = 0;

    private const STATE_LEARNING = 1;

    private const STATE_REVIEW = 2;

    private const STATE_RELEARNING = 3;

    private function __construct() {}

    /**
     * @return array<string, int|float|string|null>
     */
    public static function freshNew(?Carbon $now = null): array
    {
        return self::fresh(
            studyStatus: CardStudyStatus::New,
            dueAt: $now ?? now(),
            now: $now,
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public static function fresh(
        CardStudyStatus $studyStatus,
        ?Carbon $dueAt = null,
        ?Carbon $now = null,
    ): array {
        $now ??= now();
        $dueAt ??= $now;

        return self::serialize(
            dueAt: $dueAt,
            stability: self::DEFAULT_STABILITY,
            difficulty: self::DEFAULT_DIFFICULTY,
            elapsedDays: 0,
            scheduledDays: self::scheduledDays($dueAt, $now),
            learningSteps: 0,
            reps: 0,
            lapses: 0,
            state: self::stateForStudyStatus($studyStatus),
            lastReview: null,
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public static function dueOverride(
        Card $card,
        CardStudyStatus $studyStatus,
        Carbon $dueAt,
        Carbon $now,
    ): array {
        $existing = self::existing($card);
        $existingDue = self::due($existing);
        $dueIsUnchanged = $existingDue !== null && $existingDue->equalTo($dueAt);

        return self::serialize(
            dueAt: $dueAt,
            stability: self::numeric($existing, 'stability', self::DEFAULT_STABILITY),
            difficulty: self::numeric($existing, 'difficulty', self::DEFAULT_DIFFICULTY),
            elapsedDays: self::integer($existing, 'elapsed_days', 0),
            scheduledDays: $dueIsUnchanged
                ? self::integer($existing, 'scheduled_days', self::scheduledDays($dueAt, $now))
                : self::scheduledDays($dueAt, $now),
            learningSteps: self::integer($existing, 'learning_steps', 0),
            reps: self::integer($existing, 'reps', 0),
            lapses: self::integer($existing, 'lapses', 0),
            state: self::stateForStudyStatus($studyStatus),
            lastReview: self::lastReview($existing),
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public static function reviewed(
        Card $card,
        CardReviewRating $rating,
        CardStudyStatus $studyStatus,
        Carbon $dueAt,
        Carbon $reviewedAt,
    ): array {
        $existing = self::existing($card);
        $lastReview = self::lastReview($existing);

        return self::serialize(
            dueAt: $dueAt,
            stability: self::numeric($existing, 'stability', self::DEFAULT_STABILITY),
            difficulty: self::numeric($existing, 'difficulty', self::DEFAULT_DIFFICULTY),
            elapsedDays: $lastReview === null ? 0 : self::scheduledDays($reviewedAt, $lastReview),
            scheduledDays: self::scheduledDays($dueAt, $reviewedAt),
            learningSteps: self::integer($existing, 'learning_steps', 0),
            reps: self::integer($existing, 'reps', 0) + 1,
            lapses: self::integer($existing, 'lapses', 0) + ($rating === CardReviewRating::Again ? 1 : 0),
            state: self::stateForStudyStatus($studyStatus),
            lastReview: $reviewedAt,
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public static function forStudyStatus(
        CardStudyStatus $studyStatus,
        ?Carbon $dueAt = null,
        ?Carbon $now = null,
    ): array {
        return self::fresh($studyStatus, $dueAt, $now);
    }

    private static function stateForStudyStatus(CardStudyStatus $studyStatus): int
    {
        return match ($studyStatus) {
            CardStudyStatus::New => self::STATE_NEW,
            CardStudyStatus::Learning => self::STATE_LEARNING,
            CardStudyStatus::Review,
            CardStudyStatus::Suspended,
            CardStudyStatus::Buried => self::STATE_REVIEW,
            CardStudyStatus::Relearning => self::STATE_RELEARNING,
        };
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private static function serialize(
        Carbon $dueAt,
        int|float $stability,
        int|float $difficulty,
        int $elapsedDays,
        int $scheduledDays,
        int $learningSteps,
        int $reps,
        int $lapses,
        int $state,
        ?Carbon $lastReview,
    ): array {
        return [
            'due' => $dueAt->toJSON(),
            'stability' => $stability,
            'difficulty' => $difficulty,
            'elapsed_days' => $elapsedDays,
            'scheduled_days' => $scheduledDays,
            'learning_steps' => $learningSteps,
            'reps' => $reps,
            'lapses' => $lapses,
            'state' => $state,
            'last_review' => $lastReview?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function existing(Card $card): ?array
    {
        return is_array($card->scheduler_state) ? $card->scheduler_state : null;
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function numeric(?array $state, string $key, int|float $default): int|float
    {
        $value = $state[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function integer(?array $state, string $key, int $default): int
    {
        $value = $state[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function lastReview(?array $state): ?Carbon
    {
        $value = $state['last_review'] ?? null;

        return self::parseNullableDate($value);
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function due(?array $state): ?Carbon
    {
        $value = $state['due'] ?? null;

        return self::parseNullableDate($value);
    }

    private static function parseNullableDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function scheduledDays(Carbon $dueAt, Carbon $from): int
    {
        $days = (int) round(($dueAt->getTimestamp() - $from->getTimestamp()) / 86400);

        return max(0, $days);
    }
}
