<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use UnexpectedValueException;

final class ResolveAchievementEarnedAtAction
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
    private array $reviewAchievementDates = [];

    /**
     * @var array<int, array{
     *     conversation: array<int, CarbonImmutable>,
     *     listening: array<int, CarbonImmutable>,
     *     doubleFeature: ?CarbonImmutable,
     *     repeat: array<int, CarbonImmutable>
     * }>
     */
    private array $studyAchievementDates = [];

    public function handle(int $userId, string $metricKey, int $threshold): ?CarbonImmutable
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement award user ID must be positive.');
        }

        if ($threshold <= 0) {
            throw new InvalidArgumentException('Achievement award threshold must be positive.');
        }

        return match ($metricKey) {
            GetAchievementProgressAction::STABLE_CARD_METRIC => $this->stableCardDate($userId, $threshold),
            GetAchievementProgressAction::REVIEW_METRIC => $this->reviewDate($userId, $threshold),
            GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => $this->conversationDate($userId, $threshold),
            GetAchievementProgressAction::LISTENING_HOUR_METRIC => $this->listeningDate($userId, $threshold),
            GetAchievementProgressAction::OLD_FRIEND_METRIC => $this->oldFriendDate($userId),
            GetAchievementProgressAction::DOUBLE_FEATURE_METRIC => $this->doubleFeatureDate($userId),
            GetAchievementProgressAction::ON_REPEAT_METRIC => $this->repeatDate($userId, $threshold),
            GetAchievementProgressAction::CORRECT_RUN_METRIC => $this->correctRunDate($userId, $threshold),
            GetAchievementProgressAction::GURU_CARD_METRIC,
            GetAchievementProgressAction::MASTER_CARD_METRIC,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC,
            GetAchievementProgressAction::BURNED_CARD_METRIC => $this->masteryDate($userId, $metricKey, $threshold),
            default => throw new InvalidArgumentException("Unsupported achievement metric {$metricKey}."),
        };
    }

    private function stableCardDate(int $userId, int $threshold): ?CarbonImmutable
    {
        $stability = $this->schedulerStabilityExpression();
        $card = Card::query()
            ->ownedByActiveDeck($userId)
            ->whereProgressionAvailable()
            ->where('cards.study_status', CardStudyStatus::Review->value)
            ->whereRaw("{$stability} >= ?", [StudyMasteryLevel::BURNED_STABILITY_DAYS])
            ->select(['cards.last_reviewed_at', 'cards.created_at'])
            // Historical stability crossings predate the award ledger. The card's
            // latest review is the closest durable timestamp available for backfill.
            ->orderByRaw('COALESCE(cards.last_reviewed_at, cards.created_at)')
            ->orderBy('cards.id')
            ->skip($threshold - 1)
            ->first();

        $earnedAt = $card?->last_reviewed_at ?? $card?->created_at;

        return $earnedAt === null ? null : CarbonImmutable::instance($earnedAt);
    }

    private function reviewDate(int $userId, int $threshold): ?CarbonImmutable
    {
        $review = CardReviewEvent::query()
            // Join from the user's indexed deck/card ownership path so high-tier
            // backfills sort only this user's review history. The relationship
            // scope's correlated EXISTS can otherwise scan the global timeline
            // while seeking a large OFFSET.
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('card_review_events.*')
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->skip($threshold - 1)
            ->first(['card_review_events.reviewed_at']);

        return $review?->reviewed_at === null
            ? null
            : CarbonImmutable::instance($review->reviewed_at);
    }

    private function listeningDate(int $userId, int $thresholdHours): ?CarbonImmutable
    {
        return $this->studyAchievementDates($userId)['listening'][$thresholdHours] ?? null;
    }

    private function oldFriendDate(int $userId): ?CarbonImmutable
    {
        return $this->reviewAchievementDates($userId)['oldFriend'];
    }

    private function correctRunDate(int $userId, int $threshold): ?CarbonImmutable
    {
        return $this->reviewAchievementDates($userId)['correctRun'][$threshold] ?? null;
    }

    /**
     * @return array{
     *     oldFriend: ?CarbonImmutable,
     *     correctRun: array<int, CarbonImmutable>,
     *     mastery: array<string, array<string, CarbonImmutable>>
     * }
     */
    private function reviewAchievementDates(int $userId): array
    {
        if (array_key_exists($userId, $this->reviewAchievementDates)) {
            return $this->reviewAchievementDates[$userId];
        }

        $lastReviewByCard = [];
        $oldFriend = null;
        $run = 0;
        $correctRun = [];
        $mastery = array_fill_keys(array_keys($this->masteryMinimums()), []);

        foreach ($this->reviewTimeline($userId) as $event) {
            $cardId = (string) $event->card_id;
            $previous = $lastReviewByCard[$cardId] ?? null;
            if ($event->rating !== CardReviewRating::Again
                && $oldFriend === null
                && $previous instanceof CarbonInterface
                && $previous->lte($event->reviewed_at->copy()->subMonthsNoOverflow(6))) {
                $oldFriend = CarbonImmutable::instance($event->reviewed_at);
            }
            $lastReviewByCard[$cardId] = $event->reviewed_at;
            $run = $event->rating === CardReviewRating::Again ? 0 : $run + 1;
            if ($run > 0 && ! isset($correctRun[$run])) {
                $correctRun[$run] = CarbonImmutable::instance($event->reviewed_at);
            }

            $stability = $event->scheduler_state_after['stability'] ?? 0;
            $stability = is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
            foreach ($this->masteryMinimums() as $metric => $minimum) {
                if ($stability >= $minimum && ! isset($mastery[$metric][$cardId])) {
                    $mastery[$metric][$cardId] = CarbonImmutable::instance($event->reviewed_at);
                }
            }
        }

        return $this->reviewAchievementDates[$userId] = [
            'oldFriend' => $oldFriend,
            'correctRun' => $correctRun,
            'mastery' => $mastery,
        ];
    }

    private function doubleFeatureDate(int $userId): ?CarbonImmutable
    {
        return $this->studyAchievementDates($userId)['doubleFeature'];
    }

    private function repeatDate(int $userId, int $threshold): ?CarbonImmutable
    {
        return $this->studyAchievementDates($userId)['repeat'][$threshold] ?? null;
    }

    private function masteryDate(int $userId, string $metricKey, int $threshold): ?CarbonImmutable
    {
        $dates = $this->masteryDates($userId)[$metricKey] ?? [];

        return $dates[$threshold - 1] ?? null;
    }

    /** @return array<string, list<CarbonImmutable>> */
    private function masteryDates(int $userId): array
    {
        if (isset($this->masteryDates[$userId])) {
            return $this->masteryDates[$userId];
        }

        $minimums = $this->masteryMinimums();
        $firstDates = $this->reviewAchievementDates($userId)['mastery'];

        $cards = Card::query()
            ->withTrashed()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->get(['cards.id', 'cards.scheduler_state', 'cards.last_reviewed_at', 'cards.created_at']);
        foreach ($cards as $card) {
            $stability = $card->scheduler_state['stability'] ?? 0;
            $stability = is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
            foreach ($minimums as $metric => $minimum) {
                if ($stability >= $minimum && ! isset($firstDates[$metric][$card->id])) {
                    $date = $card->last_reviewed_at ?? $card->created_at;
                    if ($date !== null) {
                        $firstDates[$metric][$card->id] = CarbonImmutable::instance($date);
                    }
                }
            }
        }

        foreach ($firstDates as $metric => $datesByCard) {
            usort($datesByCard, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
            $firstDates[$metric] = array_values($datesByCard);
        }

        return $this->masteryDates[$userId] = $firstDates;
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

    private function reviewTimeline(int $userId): LazyCollection
    {
        // Lifetime review achievements keep their original award dates after a
        // card or deck is archived, so these joins deliberately include trash.
        return CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('card_review_events.*')
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->cursor();
    }

    private function conversationDate(int $userId, int $thresholdHours): ?CarbonImmutable
    {
        return $this->studyAchievementDates($userId)['conversation'][$thresholdHours] ?? null;
    }

    /**
     * @return array{
     *     conversation: array<int, CarbonImmutable>,
     *     listening: array<int, CarbonImmutable>,
     *     doubleFeature: ?CarbonImmutable,
     *     repeat: array<int, CarbonImmutable>
     * }
     */
    private function studyAchievementDates(int $userId): array
    {
        if (array_key_exists($userId, $this->studyAchievementDates)) {
            return $this->studyAchievementDates[$userId];
        }

        $conversationMilliseconds = 0;
        $listeningMilliseconds = 0;
        $conversation = [];
        $listening = [];
        $doubleFeature = null;
        $repeat = [];
        $categoriesByDay = [];
        $daysByEpisode = [];

        $sessions = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get([
                'id',
                'category',
                'activity',
                'name',
                'ended_at',
                'duration_ms',
                'audio_playback_ms',
            ]);

        foreach ($sessions as $session) {
            $endedAt = CarbonImmutable::instance($session->ended_at);
            $day = $endedAt->utc()->toDateString();
            $categoriesByDay[$day][$session->category->value] = true;
            if ($doubleFeature === null && isset(
                $categoriesByDay[$day][StudyActivityCategory::Listen->value],
                $categoriesByDay[$day][StudyActivityCategory::Conversation->value],
            )) {
                $doubleFeature = $endedAt;
            }

            if ($session->category === StudyActivityCategory::Conversation) {
                $previousHours = intdiv($conversationMilliseconds, 3_600_000);
                $conversationMilliseconds += $session->duration_ms;
                $currentHours = intdiv($conversationMilliseconds, 3_600_000);
                for ($hour = $previousHours + 1; $hour <= $currentHours; $hour++) {
                    $conversation[$hour] = $endedAt;
                }
            }

            if ($session->category === StudyActivityCategory::Listen) {
                $previousHours = intdiv($listeningMilliseconds, 3_600_000);
                $listeningMilliseconds += $session->audio_playback_ms ?? 0;
                $currentHours = intdiv($listeningMilliseconds, 3_600_000);
                for ($hour = $previousHours + 1; $hour <= $currentHours; $hour++) {
                    $listening[$hour] = $endedAt;
                }
            }

            if ($session->activity !== StudyActivityKind::DailyAudio
                || $session->name === null
                || ! str_starts_with($session->name, CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX)) {
                continue;
            }

            $episode = trim(substr(
                $session->name,
                strlen(CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX),
            ));
            if ($episode === '' || isset($daysByEpisode[$episode][$day])) {
                continue;
            }

            $daysByEpisode[$episode][$day] = true;
            $dayCount = count($daysByEpisode[$episode]);
            $repeat[$dayCount] ??= $endedAt;
        }

        return $this->studyAchievementDates[$userId] = [
            'conversation' => $conversation,
            'listening' => $listening,
            'doubleFeature' => $doubleFeature,
            'repeat' => $repeat,
        ];
    }

    private function schedulerStabilityExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(json_extract(cards.scheduler_state, '$.stability') AS REAL)",
            'pgsql' => "CAST(cards.scheduler_state->>'stability' AS DOUBLE PRECISION)",
            'mysql' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(cards.scheduler_state, '$.stability')) AS DECIMAL(20, 6))",
            default => throw new UnexpectedValueException('Unsupported database driver for achievement award backfill.'),
        };
    }
}
