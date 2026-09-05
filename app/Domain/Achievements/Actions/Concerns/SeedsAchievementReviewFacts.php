<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait SeedsAchievementReviewFacts
{
    /**
     * @param  array{reviews:int,cards:int,studySessions:int}  $counts
     * @return array{
     *   currentRun:int,
     *   lastCreatedAt:?CarbonImmutable,
     *   lastCreatedId:?string,
     *   latestReviewedAt:?CarbonImmutable,
     *   latestReviewedId:?string,
     * }
     */
    private function seedReviewAndCardFacts(int $userId, array &$counts): array
    {
        /** @var array<string, array{maximumStability:float,lastReviewedAt:?CarbonImmutable,sourceUpdatedAt?:CarbonImmutable}> $cardFacts */
        $cardFacts = [];
        $currentRun = 0;
        $lastCreatedAt = null;
        $lastCreatedId = null;
        $latestReviewedAt = null;
        $latestReviewedId = null;

        foreach ($this->reviewTimeline($userId) as $event) {
            $counts['reviews']++;
            $cardId = (string) $event->card_id;
            $reviewedAt = CarbonImmutable::instance($event->reviewed_at);
            $successful = $event->rating !== CardReviewRating::Again;
            $currentRun = $successful ? $currentRun + 1 : 0;
            $fact = $cardFacts[$cardId] ?? ['maximumStability' => 0.0, 'lastReviewedAt' => null];
            $fact['maximumStability'] = max(
                $fact['maximumStability'],
                $this->cardProjectionUpdater->schedulerStability($event->scheduler_state_after),
            );
            $fact['lastReviewedAt'] = $reviewedAt;
            $cardFacts[$cardId] = $fact;
            $latestReviewedAt = $reviewedAt;
            $latestReviewedId = (string) $event->id;

            $createdAt = CarbonImmutable::instance($event->created_at);
            if ($this->isReviewCreatedAfterCursor($event, $createdAt, $lastCreatedAt, $lastCreatedId)) {
                $lastCreatedAt = $createdAt;
                $lastCreatedId = (string) $event->id;
            }
        }

        foreach ($this->ownedCards($userId)->orderBy('cards.id')->cursor() as $card) {
            $counts['cards']++;
            $cardId = (string) $card->id;
            $fact = $cardFacts[$cardId] ?? ['maximumStability' => 0.0, 'lastReviewedAt' => null];
            $fact['maximumStability'] = max(
                $fact['maximumStability'],
                $this->cardProjectionUpdater->schedulerStability($card->scheduler_state),
            );
            $fact['sourceUpdatedAt'] = CarbonImmutable::instance($card->updated_at);
            $cardFacts[$cardId] = $fact;
        }

        $this->persistSeededCardFacts($userId, $cardFacts);

        return compact(
            'currentRun',
            'lastCreatedAt',
            'lastCreatedId',
            'latestReviewedAt',
            'latestReviewedId',
        );
    }

    private function isReviewCreatedAfterCursor(
        CardReviewEvent $event,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $lastCreatedAt,
        ?string $lastCreatedId,
    ): bool {
        if ($lastCreatedAt === null) {
            return true;
        }

        if ($createdAt->gt($lastCreatedAt)) {
            return true;
        }

        if (! $createdAt->equalTo($lastCreatedAt)) {
            return false;
        }

        return strcmp((string) $event->id, (string) $lastCreatedId) > 0;
    }

    /** @param array<string, array{maximumStability:float,lastReviewedAt:?CarbonImmutable,sourceUpdatedAt?:CarbonImmutable}> $cardFacts */
    private function persistSeededCardFacts(int $userId, array $cardFacts): void
    {
        $now = now();
        collect($cardFacts)->map(
            static fn (array $fact, string $cardId): array => [
                'card_id' => $cardId,
                'user_id' => $userId,
                'maximum_stability' => $fact['maximumStability'],
                'last_reviewed_at' => $fact['lastReviewedAt'],
                'source_updated_at' => $fact['sourceUpdatedAt'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        )->chunk(500)->each(
            static fn ($rows) => DB::table('achievement_card_projections')->upsert(
                $rows->values()->all(),
                ['card_id'],
                ['maximum_stability', 'last_reviewed_at', 'source_updated_at', 'updated_at'],
            ),
        );
    }
}
