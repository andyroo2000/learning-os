<?php

namespace App\Domain\Study\Actions;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudyOverviewCardsQuery;
use Illuminate\Database\Eloquent\Collection;

class GetStudyLearningReadinessAction
{
    private const TARGET_RECALL = 0.9;

    private const MINIMUM_TIMED_REVIEW_SAMPLE_SIZE = 10;

    private const MINIMUM_READINESS_SAMPLE_SIZE = 30;

    private const PAUSE_RECALL_THRESHOLD = 0.80;

    private const EASE_UP_RECALL_THRESHOLD = 0.88;

    private const STEADY_RECALL_THRESHOLD = 0.93;

    private const STRONG_RECALL_THRESHOLD = 0.97;

    private const READY_HEADROOM_MINUTES = 15;

    private const STRONG_HEADROOM_MINUTES = 30;

    public function __construct(private readonly StudyOverviewCardsQuery $cardsQuery) {}

    /** @return array<string, mixed> */
    public function handle(StudyLearningReadinessInput $input): array
    {
        $reviews = $this->recentReviews($input);
        $reviewStats = $this->reviewStats($reviews);
        $workload = ['projected_seven_day_reviews' => $this->projectedSevenDayReviews($input)];
        $timing = $this->reviewTiming($reviews, $input, $workload);
        $level = $this->readinessLevel($input, $reviewStats, $timing);
        $recommendation = match ($level) {
            'pause' => 'pause',
            'ease_up' => 'caution',
            default => 'ready',
        };

        return [
            'recommendation' => $recommendation,
            'readiness_level' => $level,
            'sample_size' => $reviewStats['sample_size'],
            'sufficient_data' => $reviewStats['sample_size'] >= self::MINIMUM_READINESS_SAMPLE_SIZE,
            'recent_recall' => $reviewStats['recent_recall'],
            'target_recall' => self::TARGET_RECALL,
            'due_backlog' => $input->dueCount(),
            'apprentice_count' => $input->apprenticeCount(),
            'projected_seven_day_reviews' => $workload['projected_seven_day_reviews'],
            'timed_review_sample_size' => $timing['timed_review_sample_size'],
            'median_review_duration_seconds' => $timing['median_review_duration_seconds'],
            'projected_daily_review_minutes' => $timing['projected_daily_review_minutes'],
            'review_time_budget_minutes' => $input->reviewTimeBudgetMinutes(),
            'review_time_headroom_minutes' => $timing['review_time_headroom_minutes'],
            'suggested_batch_size' => $this->suggestedBatchSize($input, $level),
            'display_status' => match ($recommendation) {
                'pause' => 'Reviews first recommended',
                'caution' => 'Add carefully',
                default => 'Ready to learn',
            },
            'display_summary' => $this->displaySummary($input, $reviewStats, $workload),
        ];
    }

    /** @return Collection<int, CardReviewEvent> */
    private function recentReviews(StudyLearningReadinessInput $input): Collection
    {
        return CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $input->userId())
            ->whereNull('decks.deleted_at')
            ->whereNull('cards.deleted_at')
            ->when($input->courseId() !== null, fn ($query) => $query->where('decks.course_id', $input->courseId()))
            ->when($input->deckId() !== null, fn ($query) => $query->where('cards.deck_id', $input->deckId()))
            ->where('card_review_events.reviewed_at', '>=', $input->now()->copy()->subDays(14))
            ->orderByDesc('card_review_events.reviewed_at')
            ->orderByDesc('card_review_events.id')
            ->limit(100)
            ->get(['card_review_events.rating', 'card_review_events.duration_ms']);
    }

    /**
     * @param  Collection<int, CardReviewEvent>  $reviews
     * @return array{sample_size: int, recent_recall: float|null}
     */
    private function reviewStats(Collection $reviews): array
    {
        return [
            'sample_size' => $reviews->count(),
            'recent_recall' => $reviews->isEmpty() ? null : round(
                $reviews->reject(
                    fn (CardReviewEvent $review): bool => $review->rating === CardReviewRating::Again,
                )->count() / $reviews->count(),
                3,
            ),
        ];
    }

    private function projectedSevenDayReviews(StudyLearningReadinessInput $input): int
    {
        return $this->cardsQuery->forUser($input->userId(), $input->courseId(), $input->deckId())
            ->whereProgressionAvailable()
            ->whereIn('cards.study_status', $this->cardsQuery->activeDueStatuses())
            ->whereNotNull('cards.due_at')
            ->where('cards.due_at', '<=', $input->now()->copy()->addDays(7))
            ->count('cards.id');
    }

    /**
     * @param  Collection<int, CardReviewEvent>  $reviews
     * @param  array{projected_seven_day_reviews: int}  $workload
     * @return array{timed_review_sample_size: int, median_review_duration_seconds: float|null, projected_daily_review_minutes: int|null, review_time_headroom_minutes: int|null}
     */
    private function reviewTiming(
        Collection $reviews,
        StudyLearningReadinessInput $input,
        array $workload,
    ): array {
        $durations = $reviews->pluck('duration_ms')
            ->filter(fn (mixed $duration): bool => is_int($duration) && $duration > 0)
            ->sort()
            ->values();
        $sampleSize = $durations->count();
        $medianMilliseconds = $sampleSize >= self::MINIMUM_TIMED_REVIEW_SAMPLE_SIZE
            ? $this->median($durations->all())
            : null;
        $projectedDailyMinutes = $medianMilliseconds === null ? null : (int) ceil(
            $workload['projected_seven_day_reviews'] * $medianMilliseconds / 7 / 60_000,
        );

        return [
            'timed_review_sample_size' => $sampleSize,
            'median_review_duration_seconds' => $medianMilliseconds === null ? null : round($medianMilliseconds / 1000, 1),
            'projected_daily_review_minutes' => $projectedDailyMinutes,
            'review_time_headroom_minutes' => $projectedDailyMinutes === null
                ? null
                : $input->reviewTimeBudgetMinutes() - $projectedDailyMinutes,
        ];
    }

    /**
     * @param  array{sample_size: int, recent_recall: float|null}  $reviewStats
     * @param  array{projected_daily_review_minutes: int|null, review_time_headroom_minutes: int|null}  $timing
     */
    private function readinessLevel(StudyLearningReadinessInput $input, array $reviewStats, array $timing): string
    {
        $recall = $reviewStats['recent_recall'];
        $projectedMinutes = $timing['projected_daily_review_minutes'];
        $headroomMinutes = $timing['review_time_headroom_minutes'];

        return match (true) {
            $reviewStats['sample_size'] < self::MINIMUM_READINESS_SAMPLE_SIZE || $recall === null => 'baseline',
            $recall < self::PAUSE_RECALL_THRESHOLD => 'pause',
            $recall < self::EASE_UP_RECALL_THRESHOLD => 'ease_up',
            $projectedMinutes !== null && $projectedMinutes > $input->reviewTimeBudgetMinutes() => 'ease_up',
            $recall < self::STEADY_RECALL_THRESHOLD => 'steady',
            $headroomMinutes !== null && $headroomMinutes < self::READY_HEADROOM_MINUTES => 'steady',
            $recall < self::STRONG_RECALL_THRESHOLD => 'ready',
            $headroomMinutes === null => 'ready',
            $headroomMinutes < self::STRONG_HEADROOM_MINUTES => 'ready',
            default => 'strong',
        };
    }

    /**
     * @param  array{sample_size: int, recent_recall: float|null}  $reviewStats
     * @param  array{projected_seven_day_reviews: int}  $workload
     */
    private function displaySummary(StudyLearningReadinessInput $input, array $reviewStats, array $workload): string
    {
        $sampleSize = $reviewStats['sample_size'];
        $recall = $reviewStats['recent_recall'];
        $projectedReviews = $workload['projected_seven_day_reviews'];

        if ($sampleSize < self::MINIMUM_READINESS_SAMPLE_SIZE || $recall === null) {
            return sprintf(
                'Building a recommendation from your first %d answers (%s so far). Current seven-day workload: %s %s.',
                self::MINIMUM_READINESS_SAMPLE_SIZE,
                number_format($sampleSize),
                number_format($projectedReviews),
                $projectedReviews === 1 ? 'review' : 'reviews',
            );
        }

        return sprintf(
            'Recent recall is %d%% against a %d%% target. %s %s %s reinforcement, with %s %s projected over seven days.',
            (int) round($recall * 100),
            (int) round(self::TARGET_RECALL * 100),
            number_format($input->apprenticeCount()),
            $input->apprenticeCount() === 1 ? 'Apprentice card' : 'Apprentice cards',
            $input->apprenticeCount() === 1 ? 'needs' : 'need',
            number_format($projectedReviews),
            $projectedReviews === 1 ? 'review' : 'reviews',
        );
    }

    private function suggestedBatchSize(StudyLearningReadinessInput $input, string $level): int
    {
        $configured = min(
            StudySettings::MAX_LESSON_BATCH_SIZE,
            max(StudySettings::MIN_LESSON_BATCH_SIZE, $input->lessonBatchSize()),
        );

        return match ($level) {
            'pause' => StudySettings::MIN_LESSON_BATCH_SIZE,
            'ease_up' => max(StudySettings::MIN_LESSON_BATCH_SIZE, (int) ceil($configured / 2)),
            default => $configured,
        };
    }

    /** @param list<int> $values */
    private function median(array $values): float
    {
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
