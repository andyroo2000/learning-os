<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

trait ProjectsAchievementReviews
{
    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function projectNewReviews(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
        Collection $events,
    ): void {
        $metrics = $this->integerMetrics($projection->metric_values);
        $thresholdDates = $this->thresholdDates($projection->threshold_reached_at);
        $cardProjections = AchievementCardProjection::query()
            ->whereIn('card_id', $events->pluck('card_id')->map(static fn ($id): string => (string) $id)->unique())
            ->get()
            ->keyBy(static fn (AchievementCardProjection $fact): string => (string) $fact->card_id);

        foreach ($events as $event) {
            $counts['reviews']++;
            $before = $metrics;
            $reviewedAt = CarbonImmutable::instance($event->reviewed_at);
            $cardId = (string) $event->card_id;
            $metrics[GetAchievementProgressAction::REVIEW_METRIC]++;
            if ($event->rating === CardReviewRating::Again) {
                $projection->current_correct_run = 0;
            } else {
                $projection->current_correct_run++;
                $metrics[GetAchievementProgressAction::CORRECT_RUN_METRIC] = max(
                    $metrics[GetAchievementProgressAction::CORRECT_RUN_METRIC],
                    $projection->current_correct_run,
                );
            }

            $cardProjection = $cardProjections->get($cardId);
            $previousReviewAt = $cardProjection?->last_reviewed_at;
            if ($this->isOldFriendReview($event, $previousReviewAt, $reviewedAt)) {
                $metrics[GetAchievementProgressAction::OLD_FRIEND_METRIC] = 1;
            }

            $cardProjections->put($cardId, $this->cardProjectionUpdater->updateFromReview(
                $cardProjection,
                $userId,
                $event,
                $metrics,
            ));
            $thresholdDates = $this->thresholdCrossings->recordAll(
                $before,
                $metrics,
                $reviewedAt,
                $thresholdDates,
            );
            $projection->latest_reviewed_at = $reviewedAt;
            $projection->latest_reviewed_id = (string) $event->id;
        }

        if ($events->isNotEmpty()) {
            $this->completeReviewProjection(
                $projection,
                $events,
                $cardProjections,
                ['metrics' => $metrics, 'thresholdDates' => $thresholdDates],
            );
        }
    }

    private function isOldFriendReview(
        CardReviewEvent $event,
        mixed $previousReviewAt,
        CarbonImmutable $reviewedAt,
    ): bool {
        if ($event->rating === CardReviewRating::Again) {
            return false;
        }

        if (! $previousReviewAt instanceof CarbonInterface) {
            return false;
        }

        return $previousReviewAt->lte($reviewedAt->subMonthsNoOverflow(6));
    }

    /**
     * @param  Collection<int|string, AchievementCardProjection>  $cardProjections
     * @param  array{
     *     metrics:array<string, int>,
     *     thresholdDates:array<string, array<string, string>>
     * }  $projectionValues
     */
    private function completeReviewProjection(
        AchievementProgressProjection $projection,
        Collection $events,
        Collection $cardProjections,
        array $projectionValues,
    ): void {
        $lastCreated = $events->sort(
            static fn ($left, $right): int => [$left->created_at->getTimestampMs(), (string) $left->id]
                <=> [$right->created_at->getTimestampMs(), (string) $right->id],
        )->last();
        $projection->last_review_created_at = CarbonImmutable::instance($lastCreated->created_at);
        $projection->last_review_id = (string) $lastCreated->id;
        $projection->metric_values = $projectionValues['metrics'];
        $projection->threshold_reached_at = $projectionValues['thresholdDates'];
        $this->persistCardProjections($cardProjections);
    }
}
