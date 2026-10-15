<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Values\AchievementReviewFact;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Study\Enums\StudyMasteryLevel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

final class AchievementReviewEarnedAtResolver
{
    /** @var array<int, array<string, list<CarbonImmutable>>> */
    private array $masteryDates = [];

    /**
     * @var array<int, array{
     *     oldFriend: ?CarbonImmutable,
     *     correctRun: array<int, CarbonImmutable>,
     *     mastery: array<string, array<string, CarbonImmutable>>
     * }>
     */
    private array $achievementDates = [];

    public function oldFriendDate(int $userId): ?CarbonImmutable
    {
        return $this->achievementDates($userId)['oldFriend'];
    }

    public function correctRunDate(int $userId, int $threshold): ?CarbonImmutable
    {
        return $this->achievementDates($userId)['correctRun'][$threshold] ?? null;
    }

    public function masteryDate(int $userId, string $metricKey, int $threshold): ?CarbonImmutable
    {
        return ($this->masteryDates($userId)[$metricKey] ?? [])[$threshold - 1] ?? null;
    }

    /**
     * @return array{
     *     oldFriend: ?CarbonImmutable,
     *     correctRun: array<int, CarbonImmutable>,
     *     mastery: array<string, array<string, CarbonImmutable>>
     * }
     */
    private function achievementDates(int $userId): array
    {
        if (array_key_exists($userId, $this->achievementDates)) {
            return $this->achievementDates[$userId];
        }

        $lastReviewByCard = [];
        $oldFriend = null;
        $run = 0;
        $correctRun = [];
        $mastery = array_fill_keys(array_keys($this->masteryMinimums()), []);

        foreach ($this->reviewTimeline($userId) as $event) {
            $cardId = $event->cardId;
            $previous = $lastReviewByCard[$cardId] ?? null;
            if ($oldFriend === null && $this->startsOldFriendAchievement($event, $previous)) {
                $oldFriend = $event->reviewedAt;
            }
            $lastReviewByCard[$cardId] = $event->reviewedAt;
            $run = $event->rating === CardReviewRating::Again ? 0 : $run + 1;
            if ($run > 0 && ! isset($correctRun[$run])) {
                $correctRun[$run] = $event->reviewedAt;
            }
            $this->recordMasteryDates($mastery, $event, $cardId);
        }

        return $this->achievementDates[$userId] = [
            'oldFriend' => $oldFriend,
            'correctRun' => $correctRun,
            'mastery' => $mastery,
        ];
    }

    private function startsOldFriendAchievement(AchievementReviewFact $event, mixed $previous): bool
    {
        return $event->rating !== CardReviewRating::Again
            && $previous instanceof CarbonInterface
            && $previous->lte($event->reviewedAt->subMonthsNoOverflow(6));
    }

    /** @param array<string, array<string, CarbonImmutable>> $mastery */
    private function recordMasteryDates(array &$mastery, AchievementReviewFact $event, string $cardId): void
    {
        $stability = $event->stability;
        foreach ($this->masteryMinimums() as $metric => $minimum) {
            if ($stability >= $minimum && ! isset($mastery[$metric][$cardId])) {
                $mastery[$metric][$cardId] = $event->reviewedAt;
            }
        }
    }

    /** @return array<string, list<CarbonImmutable>> */
    private function masteryDates(int $userId): array
    {
        if (isset($this->masteryDates[$userId])) {
            return $this->masteryDates[$userId];
        }

        $minimums = $this->masteryMinimums();
        $firstDates = $this->achievementDates($userId)['mastery'];
        $cards = Card::query()
            ->withTrashed()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->get(['cards.id', 'cards.scheduler_state', 'cards.last_reviewed_at', 'cards.created_at']);

        foreach ($cards as $card) {
            $this->recordCurrentCardMasteryDates($firstDates, $minimums, $card);
        }
        foreach ($firstDates as $metric => $datesByCard) {
            usort($datesByCard, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
            $firstDates[$metric] = array_values($datesByCard);
        }

        return $this->masteryDates[$userId] = $firstDates;
    }

    /**
     * @param  array<string, array<string, CarbonImmutable>>  $firstDates
     * @param  array<string, int>  $minimums
     */
    private function recordCurrentCardMasteryDates(array &$firstDates, array $minimums, Card $card): void
    {
        $stability = $this->numericStability($card->scheduler_state['stability'] ?? 0);
        $date = $card->last_reviewed_at ?? $card->created_at;
        if ($date === null) {
            return;
        }

        foreach ($minimums as $metric => $minimum) {
            if ($stability >= $minimum && ! isset($firstDates[$metric][$card->id])) {
                $firstDates[$metric][$card->id] = CarbonImmutable::instance($date);
            }
        }
    }

    private function numericStability(mixed $stability): float
    {
        return is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
    }

    /** @return array<string, int> */
    private function masteryMinimums(): array
    {
        return [
            GetAchievementProgressAction::GURU_CARD_METRIC => StudyMasteryLevel::GURU_STABILITY_DAYS,
            GetAchievementProgressAction::MASTER_CARD_METRIC => StudyMasteryLevel::MASTER_STABILITY_DAYS,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => StudyMasteryLevel::ENLIGHTENED_STABILITY_DAYS,
            GetAchievementProgressAction::BURNED_CARD_METRIC => StudyMasteryLevel::BURNED_STABILITY_DAYS,
        ];
    }

    /** @return LazyCollection<int, AchievementReviewFact> */
    private function reviewTimeline(int $userId): LazyCollection
    {
        // Lifetime review achievements keep their original award dates after a
        // card or deck is archived, so these joins deliberately include trash.
        return AchievementReviewQuery::timeline($userId);
    }
}
