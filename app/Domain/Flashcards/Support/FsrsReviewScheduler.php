<?php

namespace App\Domain\Flashcards\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Support\DateTime\StrictIsoDateTime;
use Illuminate\Support\Carbon;

/**
 * Deterministic FSRS-6 scheduling compatible with ts-fsrs 5.3.3 defaults.
 *
 * Fuzzing is intentionally disabled so server and offline clients produce the
 * same state from the same card, rating, and review timestamp.
 */
final class FsrsReviewScheduler
{
    public const PROFILE = [
        'algorithm' => 'FSRS-6',
        'library' => 'ts-fsrs',
        'library_version' => '5.3.3',
        'weights' => [
            0.212,
            1.2931,
            2.3065,
            8.2956,
            6.4133,
            0.8334,
            3.0194,
            0.001,
            1.8722,
            0.1666,
            0.796,
            1.4835,
            0.0614,
            0.2629,
            1.6483,
            0.6014,
            1.8729,
            0.5425,
            0.0912,
            0.0658,
            0.1542,
        ],
        'request_retention' => 0.9,
        'maximum_interval_days' => 36500,
        'minimum_stability' => 0.001,
        'learning_steps_minutes' => [1, 10],
        'relearning_steps_minutes' => [10],
        'enable_fuzz' => false,
        'enable_short_term' => true,
    ];

    private const STATE_NEW = 0;

    private const STATE_LEARNING = 1;

    private const STATE_REVIEW = 2;

    private const STATE_RELEARNING = 3;

    private const RATING_AGAIN = 1;

    private const RATING_HARD = 2;

    private const RATING_GOOD = 3;

    private const RATING_EASY = 4;

    private const REQUEST_RETENTION = self::PROFILE['request_retention'];

    private const MAXIMUM_INTERVAL_DAYS = self::PROFILE['maximum_interval_days'];

    private const MINIMUM_STABILITY = self::PROFILE['minimum_stability'];

    /** @var list<int> */
    private const LEARNING_STEPS_MINUTES = self::PROFILE['learning_steps_minutes'];

    /** @var list<int> */
    private const RELEARNING_STEPS_MINUTES = self::PROFILE['relearning_steps_minutes'];

    /**
     * Default FSRS-6 weights from ts-fsrs 5.3.3.
     *
     * @var list<float>
     */
    private const WEIGHTS = self::PROFILE['weights'];

    private function __construct() {}

    /**
     * @return array{
     *   dueAt: Carbon,
     *   studyStatus: CardStudyStatus,
     *   schedulerState: array<string, int|float|string|null>
     * }
     */
    public static function review(
        ?array $schedulerState,
        CardStudyStatus $studyStatus,
        CardReviewRating $rating,
        Carbon $reviewedAt,
    ): array {
        $reviewedAt = $reviewedAt->copy()->utc();
        $current = self::normalizeState($schedulerState, $studyStatus, $reviewedAt);
        $elapsedDays = self::elapsedDays($current['last_review'], $reviewedAt, $current['state']);
        $ratingValue = self::ratingValue($rating);

        $next = match ($current['state']) {
            self::STATE_NEW => self::reviewLearningState(
                current: $current,
                rating: $ratingValue,
                reviewedAt: $reviewedAt,
                elapsedDays: 0,
                forceFreshMemory: true,
            ),
            self::STATE_LEARNING,
            self::STATE_RELEARNING => self::reviewLearningState(
                current: $current,
                rating: $ratingValue,
                reviewedAt: $reviewedAt,
                elapsedDays: $elapsedDays,
                forceFreshMemory: false,
            ),
            default => self::reviewReviewState(
                current: $current,
                rating: $ratingValue,
                reviewedAt: $reviewedAt,
                elapsedDays: $elapsedDays,
            ),
        };

        $next['elapsed_days'] = $elapsedDays;
        $next['reps'] = $current['reps'] + 1;
        $next['last_review'] = $reviewedAt->copy()->utc();

        $dueAt = $next['due'];

        return [
            'dueAt' => $dueAt,
            'studyStatus' => self::studyStatus($next['state']),
            'schedulerState' => self::serialize($next),
        ];
    }

    /**
     * @param  array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }  $current
     * @return array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }
     */
    private static function reviewLearningState(
        array $current,
        int $rating,
        Carbon $reviewedAt,
        int $elapsedDays,
        bool $forceFreshMemory,
    ): array {
        $memory = $forceFreshMemory
            ? self::nextMemoryState(0, 0, 0, $rating)
            : self::nextMemoryState(
                $current['difficulty'],
                $current['stability'],
                $elapsedDays,
                $rating,
            );

        $next = $current;
        $next['difficulty'] = $memory['difficulty'];
        $next['stability'] = $memory['stability'];

        return self::applyLearningSteps(
            next: $next,
            originalState: $current['state'],
            currentStep: $current['learning_steps'],
            rating: $rating,
            reviewedAt: $reviewedAt,
        );
    }

    /**
     * @param  array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }  $current
     * @return array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }
     */
    private static function reviewReviewState(
        array $current,
        int $rating,
        Carbon $reviewedAt,
        int $elapsedDays,
    ): array {
        $retrievability = self::forgettingCurve($elapsedDays, $current['stability']);
        $candidates = [];

        foreach ([
            self::RATING_AGAIN,
            self::RATING_HARD,
            self::RATING_GOOD,
            self::RATING_EASY,
        ] as $candidateRating) {
            $memory = self::nextMemoryState(
                $current['difficulty'],
                $current['stability'],
                $elapsedDays,
                $candidateRating,
                $retrievability,
            );
            $candidate = $current;
            $candidate['difficulty'] = $memory['difficulty'];
            $candidate['stability'] = $memory['stability'];
            $candidates[$candidateRating] = $candidate;
        }

        $hardDays = min(
            self::nextInterval($candidates[self::RATING_HARD]['stability']),
            self::nextInterval($candidates[self::RATING_GOOD]['stability']),
        );
        $goodDays = max(
            self::nextInterval($candidates[self::RATING_GOOD]['stability']),
            $hardDays + 1,
        );
        $easyDays = max(
            self::nextInterval($candidates[self::RATING_EASY]['stability']),
            $goodDays + 1,
        );

        foreach ([
            self::RATING_HARD => $hardDays,
            self::RATING_GOOD => $goodDays,
            self::RATING_EASY => $easyDays,
        ] as $candidateRating => $days) {
            $candidates[$candidateRating]['scheduled_days'] = $days;
            $candidates[$candidateRating]['due'] = $reviewedAt->copy()->addDays($days);
            $candidates[$candidateRating]['learning_steps'] = 0;
            $candidates[$candidateRating]['state'] = self::STATE_REVIEW;
        }

        $again = self::applyLearningSteps(
            next: $candidates[self::RATING_AGAIN],
            originalState: self::STATE_REVIEW,
            currentStep: $current['learning_steps'],
            rating: self::RATING_AGAIN,
            reviewedAt: $reviewedAt,
        );
        $again['lapses'] = $current['lapses'] + 1;
        $candidates[self::RATING_AGAIN] = $again;

        return $candidates[$rating];
    }

    /**
     * @param  array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }  $next
     * @return array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }
     */
    private static function applyLearningSteps(
        array $next,
        int $originalState,
        int $currentStep,
        int $rating,
        Carbon $reviewedAt,
    ): array {
        $step = self::learningStep($originalState, $currentStep, $rating);
        if ($step !== null) {
            $next['learning_steps'] = $step['nextStep'];
            $next['scheduled_days'] = 0;
            $next['state'] = $originalState === self::STATE_REVIEW
                ? self::STATE_RELEARNING
                : $originalState;
            if ($next['state'] === self::STATE_NEW) {
                $next['state'] = self::STATE_LEARNING;
            }
            $next['due'] = $reviewedAt->copy()->addMinutes($step['minutes']);

            return $next;
        }

        $days = self::nextInterval($next['stability']);
        $next['learning_steps'] = 0;
        $next['scheduled_days'] = $days;
        $next['state'] = self::STATE_REVIEW;
        $next['due'] = $reviewedAt->copy()->addDays($days);

        return $next;
    }

    /**
     * @return array{minutes: int, nextStep: int}|null
     */
    private static function learningStep(int $state, int $currentStep, int $rating): ?array
    {
        $steps = in_array($state, [self::STATE_REVIEW, self::STATE_RELEARNING], true)
            ? self::RELEARNING_STEPS_MINUTES
            : self::LEARNING_STEPS_MINUTES;

        if ($steps === [] || $currentStep >= count($steps)) {
            return null;
        }

        $first = $steps[0];
        if ($state === self::STATE_REVIEW) {
            return $rating === self::RATING_AGAIN
                ? ['minutes' => $steps[max(0, $currentStep)], 'nextStep' => 0]
                : null;
        }

        return match ($rating) {
            self::RATING_AGAIN => ['minutes' => $first, 'nextStep' => 0],
            self::RATING_HARD => [
                // ts-fsrs bases Hard on the first two configured steps even
                // after the card has advanced to a later learning step.
                'minutes' => count($steps) === 1
                    ? (int) round($first * 1.5)
                    : (int) round(($first + $steps[1]) / 2),
                'nextStep' => $currentStep,
            ],
            self::RATING_GOOD => isset($steps[$currentStep + 1])
                ? ['minutes' => $steps[$currentStep + 1], 'nextStep' => $currentStep + 1]
                : null,
            default => null,
        };
    }

    /**
     * @return array{difficulty: float, stability: float}
     */
    private static function nextMemoryState(
        float $difficulty,
        float $stability,
        int $elapsedDays,
        int $rating,
        ?float $retrievability = null,
    ): array {
        if ($difficulty === 0.0 && $stability === 0.0) {
            return [
                'difficulty' => self::clamp(self::initialDifficulty($rating), 1, 10),
                'stability' => max(self::WEIGHTS[$rating - 1], 0.1),
            ];
        }

        $retrievability ??= self::forgettingCurve($elapsedDays, $stability);
        if ($elapsedDays === 0) {
            $stability = self::nextShortTermStability($stability, $rating);
        } elseif ($rating === self::RATING_AGAIN) {
            $afterFailure = self::nextForgetStability(
                $difficulty,
                $stability,
                $retrievability,
            );
            $minimumAfterFailure = self::roundToEight(
                $stability / exp(self::WEIGHTS[17] * self::WEIGHTS[18]),
            );
            $stability = self::clamp(
                $minimumAfterFailure,
                self::MINIMUM_STABILITY,
                $afterFailure,
            );
        } else {
            $stability = self::nextRecallStability(
                $difficulty,
                $stability,
                $retrievability,
                $rating,
            );
        }

        return [
            'difficulty' => self::nextDifficulty($difficulty, $rating),
            'stability' => $stability,
        ];
    }

    private static function initialDifficulty(int $rating): float
    {
        return self::roundToEight(
            self::WEIGHTS[4] - exp(($rating - 1) * self::WEIGHTS[5]) + 1,
        );
    }

    private static function nextDifficulty(float $difficulty, int $rating): float
    {
        $delta = -self::WEIGHTS[6] * ($rating - 3);
        $dampedDelta = self::roundToEight($delta * (10 - $difficulty) / 9);
        $next = $difficulty + $dampedDelta;
        $reverted = self::roundToEight(
            self::WEIGHTS[7] * self::initialDifficulty(self::RATING_EASY)
                + (1 - self::WEIGHTS[7]) * $next,
        );

        return self::clamp($reverted, 1, 10);
    }

    private static function nextShortTermStability(float $stability, int $rating): float
    {
        $increase = pow($stability, -self::WEIGHTS[19])
            * exp(self::WEIGHTS[17] * ($rating - 3 + self::WEIGHTS[18]));
        if ($rating >= self::RATING_HARD) {
            $increase = max($increase, 1);
        }

        return self::roundToEight(self::clamp(
            $stability * $increase,
            self::MINIMUM_STABILITY,
            self::MAXIMUM_INTERVAL_DAYS,
        ));
    }

    private static function nextRecallStability(
        float $difficulty,
        float $stability,
        float $retrievability,
        int $rating,
    ): float {
        $hardPenalty = $rating === self::RATING_HARD ? self::WEIGHTS[15] : 1;
        $easyBonus = $rating === self::RATING_EASY ? self::WEIGHTS[16] : 1;
        $next = $stability * (
            1
            + exp(self::WEIGHTS[8])
            * (11 - $difficulty)
            * pow($stability, -self::WEIGHTS[9])
            * (exp((1 - $retrievability) * self::WEIGHTS[10]) - 1)
            * $hardPenalty
            * $easyBonus
        );

        return self::roundToEight(self::clamp(
            $next,
            self::MINIMUM_STABILITY,
            self::MAXIMUM_INTERVAL_DAYS,
        ));
    }

    private static function nextForgetStability(
        float $difficulty,
        float $stability,
        float $retrievability,
    ): float {
        $next = self::WEIGHTS[11]
            * pow($difficulty, -self::WEIGHTS[12])
            * (pow($stability + 1, self::WEIGHTS[13]) - 1)
            * exp((1 - $retrievability) * self::WEIGHTS[14]);

        return self::roundToEight(self::clamp(
            $next,
            self::MINIMUM_STABILITY,
            self::MAXIMUM_INTERVAL_DAYS,
        ));
    }

    private static function forgettingCurve(int $elapsedDays, float $stability): float
    {
        $decay = -self::WEIGHTS[20];
        $factor = self::roundToEight(exp(log(0.9) / $decay) - 1);

        return self::roundToEight(pow(
            1 + ($factor * $elapsedDays / $stability),
            $decay,
        ));
    }

    private static function nextInterval(float $stability): int
    {
        $decay = -self::WEIGHTS[20];
        $factor = self::roundToEight(exp(log(0.9) / $decay) - 1);
        $modifier = self::roundToEight(
            (pow(self::REQUEST_RETENTION, 1 / $decay) - 1) / $factor,
        );

        return min(
            max(1, (int) round($stability * $modifier)),
            self::MAXIMUM_INTERVAL_DAYS,
        );
    }

    /**
     * @return array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }
     */
    private static function normalizeState(
        ?array $state,
        CardStudyStatus $studyStatus,
        Carbon $reviewedAt,
    ): array {
        $stateNumber = self::integer($state, 'state', self::stateForStudyStatus($studyStatus));
        $isNew = $stateNumber === self::STATE_NEW;

        return [
            'due' => self::date($state, 'due') ?? $reviewedAt->copy(),
            'stability' => $isNew ? 0.0 : max(
                self::numeric($state, 'stability', 0.1),
                self::MINIMUM_STABILITY,
            ),
            'difficulty' => $isNew ? 0.0 : self::clamp(
                self::numeric($state, 'difficulty', 5),
                1,
                10,
            ),
            'elapsed_days' => max(0, self::integer($state, 'elapsed_days', 0)),
            'scheduled_days' => max(0, self::integer($state, 'scheduled_days', 0)),
            'learning_steps' => max(0, self::integer($state, 'learning_steps', 0)),
            'reps' => max(0, self::integer($state, 'reps', 0)),
            'lapses' => max(0, self::integer($state, 'lapses', 0)),
            'state' => in_array($stateNumber, [
                self::STATE_NEW,
                self::STATE_LEARNING,
                self::STATE_REVIEW,
                self::STATE_RELEARNING,
            ], true) ? $stateNumber : self::stateForStudyStatus($studyStatus),
            'last_review' => self::date($state, 'last_review'),
        ];
    }

    private static function elapsedDays(?Carbon $lastReview, Carbon $reviewedAt, int $state): int
    {
        if ($state === self::STATE_NEW || $lastReview === null) {
            return 0;
        }

        $lastDay = $lastReview->copy()->utc()->startOfDay();
        $reviewDay = $reviewedAt->copy()->utc()->startOfDay();

        return max(0, (int) floor(
            ($reviewDay->getTimestamp() - $lastDay->getTimestamp()) / 86400,
        ));
    }

    /**
     * @param  array{
     *   due: Carbon,
     *   stability: float,
     *   difficulty: float,
     *   elapsed_days: int,
     *   scheduled_days: int,
     *   learning_steps: int,
     *   reps: int,
     *   lapses: int,
     *   state: int,
     *   last_review: ?Carbon
     * }  $state
     * @return array<string, int|float|string|null>
     */
    private static function serialize(array $state): array
    {
        return [
            'due' => $state['due']->toJSON(),
            'stability' => $state['stability'],
            'difficulty' => $state['difficulty'],
            'elapsed_days' => $state['elapsed_days'],
            'scheduled_days' => $state['scheduled_days'],
            'learning_steps' => $state['learning_steps'],
            'reps' => $state['reps'],
            'lapses' => $state['lapses'],
            'state' => $state['state'],
            'last_review' => $state['last_review']?->toJSON(),
        ];
    }

    private static function ratingValue(CardReviewRating $rating): int
    {
        return match ($rating) {
            CardReviewRating::Again => self::RATING_AGAIN,
            CardReviewRating::Hard => self::RATING_HARD,
            CardReviewRating::Good => self::RATING_GOOD,
            CardReviewRating::Easy => self::RATING_EASY,
        };
    }

    private static function studyStatus(int $state): CardStudyStatus
    {
        return match ($state) {
            self::STATE_NEW => CardStudyStatus::New,
            self::STATE_LEARNING => CardStudyStatus::Learning,
            self::STATE_RELEARNING => CardStudyStatus::Relearning,
            default => CardStudyStatus::Review,
        };
    }

    private static function stateForStudyStatus(CardStudyStatus $status): int
    {
        return match ($status) {
            CardStudyStatus::New => self::STATE_NEW,
            CardStudyStatus::Learning => self::STATE_LEARNING,
            CardStudyStatus::Relearning => self::STATE_RELEARNING,
            default => self::STATE_REVIEW,
        };
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function numeric(?array $state, string $key, float $default): float
    {
        $value = $state[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : $default;
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
    private static function date(?array $state, string $key): ?Carbon
    {
        $value = $state[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return StrictIsoDateTime::parseOrNull(trim($value));
    }

    private static function clamp(float $value, float $minimum, float $maximum): float
    {
        return min(max($value, $minimum), $maximum);
    }

    private static function roundToEight(float $value): float
    {
        $factor = 100_000_000;

        return floor(($value * $factor) + 0.5) / $factor;
    }
}
