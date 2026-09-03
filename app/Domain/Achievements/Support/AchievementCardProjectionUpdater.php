<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use Carbon\CarbonImmutable;

final class AchievementCardProjectionUpdater
{
    public function __construct(
        private readonly AchievementThresholdCrossingTracker $thresholdCrossings,
    ) {}

    /** @param array<string, int> $metrics */
    public function updateFromReview(
        ?AchievementCardProjection $fact,
        int $userId,
        CardReviewEvent $event,
        array &$metrics,
    ): AchievementCardProjection {
        $maximum = $this->maximumStability($fact, $event->scheduler_state_after, $metrics);
        $fact = $this->initializeFact($fact, $userId, (string) $event->card_id, $maximum);
        $fact->last_reviewed_at = CarbonImmutable::instance($event->reviewed_at);
        $fact->source_updated_at = CarbonImmutable::parse($event->getAttribute('card_source_updated_at'));

        return $this->touch($fact);
    }

    /** @param array<string, int> $metrics */
    public function updateFromCard(
        ?AchievementCardProjection $fact,
        int $userId,
        Card $card,
        array &$metrics,
    ): AchievementCardProjection {
        $maximum = $this->maximumStability($fact, $card->scheduler_state, $metrics);
        $fact = $this->initializeFact($fact, $userId, (string) $card->id, $maximum);
        $fact->source_updated_at = CarbonImmutable::instance($card->updated_at);

        return $this->touch($fact);
    }

    /** @param array<string, mixed>|null $schedulerState @param array<string, int> $metrics */
    private function maximumStability(
        ?AchievementCardProjection $fact,
        ?array $schedulerState,
        array &$metrics,
    ): float {
        $previousMaximum = $fact?->maximum_stability ?? 0.0;
        $maximum = max($previousMaximum, $this->schedulerStability($schedulerState));
        if ($maximum > $previousMaximum) {
            foreach ($this->thresholdCrossings->masteryThresholds() as $metricKey => $minimumStability) {
                if ($previousMaximum < $minimumStability && $maximum >= $minimumStability) {
                    $metrics[$metricKey]++;
                }
            }
        }

        return $maximum;
    }

    private function initializeFact(
        ?AchievementCardProjection $fact,
        int $userId,
        string $cardId,
        float $maximumStability,
    ): AchievementCardProjection {
        $fact ??= new AchievementCardProjection;
        $fact->card_id = $cardId;
        $fact->user_id = $userId;
        $fact->maximum_stability = $maximumStability;

        return $fact;
    }

    private function touch(AchievementCardProjection $fact): AchievementCardProjection
    {
        $fact->created_at = $fact->created_at ?? now();
        $fact->updated_at = now();

        return $fact;
    }

    /** @param array<string, mixed>|null $schedulerState */
    public function schedulerStability(?array $schedulerState): float
    {
        $stability = $schedulerState['stability'] ?? 0;

        return is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
    }
}
