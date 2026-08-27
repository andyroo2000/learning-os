<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final class ResolveAchievementEarnedAtAction
{
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
            // Historical stability crossings predate the award ledger. The card's
            // latest review is the closest durable timestamp available for backfill.
            ->orderByRaw('COALESCE(cards.last_reviewed_at, cards.created_at)')
            ->orderBy('cards.id')
            ->skip($threshold - 1)
            ->first(['cards.last_reviewed_at', 'cards.created_at']);

        $earnedAt = $card?->last_reviewed_at ?? $card?->created_at;

        return $earnedAt === null ? null : CarbonImmutable::instance($earnedAt);
    }

    private function reviewDate(int $userId, int $threshold): ?CarbonImmutable
    {
        $review = CardReviewEvent::query()
            ->ownedByActiveCardDeck($userId)
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->skip($threshold - 1)
            ->first(['reviewed_at']);

        return $review?->reviewed_at === null
            ? null
            : CarbonImmutable::instance($review->reviewed_at);
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
