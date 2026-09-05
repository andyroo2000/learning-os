<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Flashcards\Models\Card;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

trait ProjectsAchievementCards
{
    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function projectChangedCards(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
    ): void {
        $cards = $this->changedCards($userId);
        if ($cards->isEmpty()) {
            return;
        }

        $metrics = $this->integerMetrics($projection->metric_values);
        $thresholdDates = $this->thresholdDates($projection->threshold_reached_at);
        $cardProjections = AchievementCardProjection::query()
            ->whereIn('card_id', $cards->pluck('id')->map(static fn ($id): string => (string) $id))
            ->get()
            ->keyBy(static fn (AchievementCardProjection $fact): string => (string) $fact->card_id);

        foreach ($cards as $card) {
            $counts['cards']++;
            $before = $metrics;
            $cardId = (string) $card->id;
            $crossedAt = CarbonImmutable::instance($card->last_reviewed_at ?? $card->created_at);
            $cardProjections->put($cardId, $this->cardProjectionUpdater->updateFromCard(
                $cardProjections->get($cardId),
                $userId,
                $card,
                $metrics,
            ));
            $thresholdDates = $this->thresholdCrossings->recordAll(
                $before,
                $metrics,
                $crossedAt,
                $thresholdDates,
            );
        }
        $this->persistCardProjections($cardProjections);

        $projection->metric_values = $metrics;
        $projection->threshold_reached_at = $thresholdDates;
    }

    /** @return Collection<int, Card> */
    private function changedCards(int $userId): Collection
    {
        return $this->ownedCards($userId)
            ->leftJoin(
                'achievement_card_projections',
                'achievement_card_projections.card_id',
                '=',
                'cards.id',
            )
            ->where(function (Builder $query): void {
                $query->whereNull('achievement_card_projections.card_id')
                    ->orWhereNull('achievement_card_projections.source_updated_at')
                    ->orWhereColumn('cards.updated_at', '>', 'achievement_card_projections.source_updated_at');
            })
            ->get()
            ->sort(static function (Card $left, Card $right): int {
                $leftAt = CarbonImmutable::instance($left->last_reviewed_at ?? $left->created_at);
                $rightAt = CarbonImmutable::instance($right->last_reviewed_at ?? $right->created_at);
                $dateComparison = $leftAt <=> $rightAt;

                return $dateComparison !== 0
                    ? $dateComparison
                    : strcmp((string) $left->id, (string) $right->id);
            })
            ->values();
    }

    /** @param Collection<int|string, AchievementCardProjection> $facts */
    private function persistCardProjections(Collection $facts): void
    {
        $facts->map(static fn (AchievementCardProjection $fact): array => [
            'card_id' => (string) $fact->card_id,
            'user_id' => $fact->user_id,
            'maximum_stability' => $fact->maximum_stability,
            'last_reviewed_at' => $fact->last_reviewed_at,
            'source_updated_at' => $fact->source_updated_at,
            'created_at' => $fact->created_at,
            'updated_at' => $fact->updated_at,
        ])->values()->chunk(500)->each(
            static fn ($rows) => DB::table('achievement_card_projections')->upsert(
                $rows->all(),
                ['card_id'],
                [
                    'user_id',
                    'maximum_stability',
                    'last_reviewed_at',
                    'source_updated_at',
                    'updated_at',
                ],
            ),
        );
    }
}
