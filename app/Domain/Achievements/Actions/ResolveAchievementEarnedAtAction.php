<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Support\AchievementReviewEarnedAtResolver;
use App\Domain\Achievements\Support\AchievementStudyEarnedAtResolver;
use App\Domain\Achievements\Values\AchievementMetricTarget;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyMasteryLevel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final class ResolveAchievementEarnedAtAction
{
    public function __construct(
        private readonly AchievementReviewEarnedAtResolver $reviewResolver,
        private readonly AchievementStudyEarnedAtResolver $studyResolver,
    ) {}

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
            GetAchievementProgressAction::CONVERSATION_HOUR_METRIC,
            GetAchievementProgressAction::LISTENING_HOUR_METRIC,
            GetAchievementProgressAction::DOUBLE_FEATURE_METRIC,
            GetAchievementProgressAction::ON_REPEAT_METRIC => $this->studyResolver->date(
                new AchievementMetricTarget($userId, $metricKey, $threshold),
            ),
            GetAchievementProgressAction::OLD_FRIEND_METRIC => $this->reviewResolver->oldFriendDate($userId),
            GetAchievementProgressAction::CORRECT_RUN_METRIC => $this->reviewResolver->correctRunDate($userId, $threshold),
            GetAchievementProgressAction::GURU_CARD_METRIC,
            GetAchievementProgressAction::MASTER_CARD_METRIC,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC,
            GetAchievementProgressAction::BURNED_CARD_METRIC => $this->reviewResolver->masteryDate(
                $userId,
                $metricKey,
                $threshold,
            ),
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
