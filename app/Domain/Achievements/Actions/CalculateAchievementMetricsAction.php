<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class CalculateAchievementMetricsAction
{
    public const DAILY_AUDIO_COMPLETION_PREFIX = 'Daily Audio completed: ';

    /** @return array<string, int> */
    public function handle(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement metric user ID must be positive.');
        }

        $reviewMetrics = $this->reviewMetrics($userId);
        $studyMetrics = $this->studyMetrics($userId);

        return [
            ...$reviewMetrics,
            ...$studyMetrics,
        ];
    }

    /** @return array<string, int> */
    private function reviewMetrics(int $userId): array
    {
        $longestRun = 0;
        $currentRun = 0;
        $oldFriend = 0;
        $lastReviewByCard = [];
        $masteryCards = [
            GetAchievementProgressAction::GURU_CARD_METRIC => [],
            GetAchievementProgressAction::MASTER_CARD_METRIC => [],
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => [],
            GetAchievementProgressAction::BURNED_CARD_METRIC => [],
        ];

        $events = CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('card_review_events.*')
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->cursor();

        $reviewCount = 0;
        foreach ($events as $event) {
            $reviewCount++;
            $cardId = (string) $event->card_id;
            $reviewedAt = $event->reviewed_at;
            $successful = $event->rating !== CardReviewRating::Again;

            if ($successful) {
                $currentRun++;
                $longestRun = max($longestRun, $currentRun);
                $previousReviewAt = $lastReviewByCard[$cardId] ?? null;
                if ($previousReviewAt instanceof CarbonInterface
                    && $previousReviewAt->lte($reviewedAt->copy()->subMonthsNoOverflow(6))) {
                    $oldFriend = 1;
                }
            } else {
                $currentRun = 0;
            }
            $lastReviewByCard[$cardId] = $reviewedAt;

            $this->recordMasteryCrossings(
                $masteryCards,
                $cardId,
                $this->stability($event->scheduler_state_after),
            );
        }

        // Imported cards can already be mature before their first ConvoLab review.
        $cards = Card::query()
            ->withTrashed()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->get(['cards.id', 'cards.scheduler_state']);
        foreach ($cards as $card) {
            $this->recordMasteryCrossings(
                $masteryCards,
                (string) $card->id,
                $this->stability($card->scheduler_state),
            );
        }

        return [
            GetAchievementProgressAction::REVIEW_METRIC => $reviewCount,
            GetAchievementProgressAction::CORRECT_RUN_METRIC => $longestRun,
            GetAchievementProgressAction::OLD_FRIEND_METRIC => $oldFriend,
            GetAchievementProgressAction::GURU_CARD_METRIC => count($masteryCards[GetAchievementProgressAction::GURU_CARD_METRIC]),
            GetAchievementProgressAction::MASTER_CARD_METRIC => count($masteryCards[GetAchievementProgressAction::MASTER_CARD_METRIC]),
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => count($masteryCards[GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC]),
            GetAchievementProgressAction::BURNED_CARD_METRIC => count($masteryCards[GetAchievementProgressAction::BURNED_CARD_METRIC]),
        ];
    }

    /**
     * @param  array<string, array<string, true>>  $masteryCards
     */
    private function recordMasteryCrossings(array &$masteryCards, string $cardId, float $stability): void
    {
        foreach ([
            GetAchievementProgressAction::GURU_CARD_METRIC => StudyMasteryLevel::GURU_STABILITY_DAYS,
            GetAchievementProgressAction::MASTER_CARD_METRIC => 30,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => 90,
            GetAchievementProgressAction::BURNED_CARD_METRIC => StudyMasteryLevel::BURNED_STABILITY_DAYS,
        ] as $metricKey => $minimumStability) {
            if ($stability >= $minimumStability) {
                $masteryCards[$metricKey][$cardId] = true;
            }
        }
    }

    /** @param array<string, mixed>|null $schedulerState */
    private function stability(?array $schedulerState): float
    {
        $stability = $schedulerState['stability'] ?? 0;

        return is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
    }

    /** @return array<string, int> */
    private function studyMetrics(int $userId): array
    {
        $conversationMilliseconds = 0;
        $listeningMilliseconds = 0;
        $categoriesByDay = [];
        $listeningDaysByEpisode = [];

        $sessions = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $day = $session->ended_at->utc()->toDateString();
            $categoriesByDay[$day][$session->category->value] = true;

            if ($session->category === StudyActivityCategory::Conversation) {
                $conversationMilliseconds += $session->duration_ms;
            }
            if ($session->category === StudyActivityCategory::Listen) {
                $listeningMilliseconds += $session->audio_playback_ms ?? 0;
            }
            if ($session->activity === StudyActivityKind::DailyAudio
                && $session->name !== null
                && str_starts_with($session->name, self::DAILY_AUDIO_COMPLETION_PREFIX)) {
                $episode = trim(substr($session->name, strlen(self::DAILY_AUDIO_COMPLETION_PREFIX)));
                if ($episode !== '') {
                    $listeningDaysByEpisode[$episode][$day] = true;
                }
            }
        }

        $doubleFeature = collect($categoriesByDay)->contains(
            static fn (array $categories): bool => isset(
                $categories[StudyActivityCategory::Listen->value],
                $categories[StudyActivityCategory::Conversation->value],
            ),
        );
        $repeatDays = collect($listeningDaysByEpisode)
            ->map(static fn (array $days): int => count($days))
            ->max() ?? 0;

        return [
            GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => intdiv($conversationMilliseconds, 3_600_000),
            GetAchievementProgressAction::LEGACY_CONVERSATION_MINUTE_METRIC => intdiv($conversationMilliseconds, 60_000),
            GetAchievementProgressAction::LISTENING_HOUR_METRIC => intdiv($listeningMilliseconds, 3_600_000),
            GetAchievementProgressAction::DOUBLE_FEATURE_METRIC => $doubleFeature ? 1 : 0,
            GetAchievementProgressAction::ON_REPEAT_METRIC => $repeatDays,
        ];
    }
}
