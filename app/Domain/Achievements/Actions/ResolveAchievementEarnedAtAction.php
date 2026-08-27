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
        $thresholdMilliseconds = $thresholdHours * 3_600_000;
        $accumulatedMilliseconds = 0;

        $sessions = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('category', StudyActivityCategory::Listen->value)
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get(['id', 'ended_at', 'audio_playback_ms']);
        foreach ($sessions as $session) {
            $accumulatedMilliseconds += $session->audio_playback_ms ?? 0;
            if ($accumulatedMilliseconds >= $thresholdMilliseconds) {
                return CarbonImmutable::instance($session->ended_at);
            }
        }

        return null;
    }

    private function oldFriendDate(int $userId): ?CarbonImmutable
    {
        $lastReviewByCard = [];
        $events = $this->reviewTimeline($userId);

        foreach ($events as $event) {
            $cardId = (string) $event->card_id;
            $previous = $lastReviewByCard[$cardId] ?? null;
            if ($event->rating !== CardReviewRating::Again
                && $previous instanceof CarbonInterface
                && $previous->lte($event->reviewed_at->copy()->subMonthsNoOverflow(6))) {
                return CarbonImmutable::instance($event->reviewed_at);
            }
            $lastReviewByCard[$cardId] = $event->reviewed_at;
        }

        return null;
    }

    private function correctRunDate(int $userId, int $threshold): ?CarbonImmutable
    {
        $run = 0;
        foreach ($this->reviewTimeline($userId) as $event) {
            $run = $event->rating === CardReviewRating::Again ? 0 : $run + 1;
            if ($run >= $threshold) {
                return CarbonImmutable::instance($event->reviewed_at);
            }
        }

        return null;
    }

    private function doubleFeatureDate(int $userId): ?CarbonImmutable
    {
        $categoriesByDay = [];
        $sessions = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->whereIn('category', [
                StudyActivityCategory::Listen->value,
                StudyActivityCategory::Conversation->value,
            ])
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get(['id', 'category', 'ended_at']);

        foreach ($sessions as $session) {
            $day = $session->ended_at->utc()->toDateString();
            $categoriesByDay[$day][$session->category->value] = true;
            if (count($categoriesByDay[$day]) === 2) {
                return CarbonImmutable::instance($session->ended_at);
            }
        }

        return null;
    }

    private function repeatDate(int $userId, int $threshold): ?CarbonImmutable
    {
        $daysByEpisode = [];
        $sessions = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('activity', StudyActivityKind::DailyAudio->value)
            ->whereNotNull('name')
            ->where('name', 'like', CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX.'%')
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get(['id', 'name', 'ended_at']);

        foreach ($sessions as $session) {
            $episode = trim(substr(
                $session->name,
                strlen(CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX),
            ));
            if ($episode === '') {
                continue;
            }
            $daysByEpisode[$episode][$session->ended_at->utc()->toDateString()] = true;
            if (count($daysByEpisode[$episode]) >= $threshold) {
                return CarbonImmutable::instance($session->ended_at);
            }
        }

        return null;
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

        $minimums = [
            GetAchievementProgressAction::GURU_CARD_METRIC => StudyMasteryLevel::GURU_STABILITY_DAYS,
            GetAchievementProgressAction::MASTER_CARD_METRIC => 30,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => 90,
            GetAchievementProgressAction::BURNED_CARD_METRIC => StudyMasteryLevel::BURNED_STABILITY_DAYS,
        ];
        $firstDates = array_fill_keys(array_keys($minimums), []);

        foreach ($this->reviewTimeline($userId) as $event) {
            $stability = $event->scheduler_state_after['stability'] ?? 0;
            $stability = is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
            foreach ($minimums as $metric => $minimum) {
                if ($stability >= $minimum && ! isset($firstDates[$metric][$event->card_id])) {
                    $firstDates[$metric][$event->card_id] = CarbonImmutable::instance($event->reviewed_at);
                }
            }
        }

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

    private function reviewTimeline(int $userId): LazyCollection
    {
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
        $thresholdMilliseconds = $thresholdHours * 3_600_000;
        $accumulatedMilliseconds = 0;

        $sessions = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('category', StudyActivityCategory::Conversation->value)
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get(['id', 'ended_at', 'duration_ms']);

        foreach ($sessions as $session) {
            $accumulatedMilliseconds += $session->duration_ms;
            if ($accumulatedMilliseconds >= $thresholdMilliseconds) {
                return CarbonImmutable::instance($session->ended_at);
            }
        }

        return null;
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
