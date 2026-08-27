<?php

namespace App\Domain\Study\Services;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Study\Enums\NewCardLane;
use App\Domain\Study\Models\StudySettings;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SelectNewStudyCards
{
    /**
     * @param  Builder<Card>  $baseQuery
     * @return Collection<int, Card>
     */
    public function handle(
        Builder $baseQuery,
        int $userId,
        int $limit,
        Carbon $now,
        ?string $timeZone = null,
        ?Carbon $availabilityThrough = null,
    ): Collection {
        if ($limit <= 0) {
            return collect();
        }

        $weights = $this->weights($userId);
        $introducedCounts = $this->introducedCounts($userId, $now, $timeZone);
        $queues = collect(NewCardLane::cases())
            ->mapWithKeys(function (NewCardLane $lane) use ($availabilityThrough, $baseQuery, $limit, $now, $weights): array {
                if ($weights[$lane->value] === 0) {
                    return [$lane->value => collect()];
                }

                $query = clone $baseQuery;
                $this->applyLane($query, $lane, $now, $availabilityThrough ?? $now);

                return [$lane->value => NewCardQueueOrdering::positionedCards($query)
                    ->limit($limit)
                    ->get()
                    ->values()];
            });

        $selected = collect();
        while ($selected->count() < $limit) {
            $availableLanes = collect(NewCardLane::cases())
                ->filter(fn (NewCardLane $lane): bool => $weights[$lane->value] > 0
                    && $queues[$lane->value]->isNotEmpty());
            if ($availableLanes->isEmpty()) {
                break;
            }

            /** @var NewCardLane $lane */
            $lane = $availableLanes
                ->sortBy(fn (NewCardLane $candidate): float => $introducedCounts[$candidate->value]
                    / $weights[$candidate->value])
                ->first();
            $selected->push($queues[$lane->value]->shift());
            $introducedCounts[$lane->value]++;
        }

        return $selected->values();
    }

    /** @return array<string, int> */
    private function weights(int $userId): array
    {
        $settings = StudySettings::query()->where('user_id', $userId)->first();

        return [
            NewCardLane::Standard->value => $settings?->standard_lane_weight
                ?? StudySettings::DEFAULT_STANDARD_LANE_WEIGHT,
            NewCardLane::LessonFollowup->value => $settings?->lesson_followup_lane_weight
                ?? StudySettings::DEFAULT_LESSON_FOLLOWUP_LANE_WEIGHT,
            NewCardLane::WaniKani->value => $settings?->wanikani_lane_weight
                ?? StudySettings::DEFAULT_WANIKANI_LANE_WEIGHT,
        ];
    }

    /** @return array<string, int> */
    private function introducedCounts(int $userId, Carbon $now, ?string $timeZone): array
    {
        $resolvedTimeZone = trim($timeZone ?? '') ?: 'UTC';
        try {
            new DateTimeZone($resolvedTimeZone);
        } catch (\Exception) {
            throw new InvalidArgumentException('Study time_zone must be a valid IANA timezone.');
        }
        $localDayStart = $now->copy()->setTimezone($resolvedTimeZone)->startOfDay();
        $dayStart = $localDayStart->copy()->setTimezone('UTC');
        // Add a calendar day before converting to UTC. DST transition days are
        // 23 or 25 hours long, so adding 24 hours to the UTC boundary is wrong.
        $dayEnd = $localDayStart->copy()->addDay()->setTimezone('UTC');
        $counts = DB::table('cards')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereNull('cards.deleted_at')
            ->where('cards.introduced_at', '>=', $dayStart)
            ->where('cards.introduced_at', '<', $dayEnd)
            ->selectRaw('cards.selection_policy, COUNT(*) AS aggregate')
            ->groupBy('cards.selection_policy')
            ->pluck('aggregate', 'selection_policy');

        return [
            NewCardLane::Standard->value => (int) $counts->get(CardSelectionPolicy::Standard->value, 0)
                + (int) $counts->get(null, 0),
            NewCardLane::LessonFollowup->value => (int) $counts->get(CardSelectionPolicy::ReviewSoon->value, 0),
            NewCardLane::WaniKani->value => (int) $counts->get(CardSelectionPolicy::Sprinkled->value, 0),
        ];
    }

    /** @param Builder<Card> $query */
    private function applyLane(
        Builder $query,
        NewCardLane $lane,
        Carbon $now,
        Carbon $availabilityThrough,
    ): void {
        $query->where(function (Builder $query) use ($availabilityThrough): void {
            $query->whereNull('cards.introduction_available_at')
                ->orWhere('cards.introduction_available_at', '<=', $availabilityThrough);
        });

        if ($lane === NewCardLane::Standard) {
            $query->where(function (Builder $query) use ($now): void {
                $query->whereNull('cards.selection_policy')
                    ->orWhere('cards.selection_policy', CardSelectionPolicy::Standard->value)
                    // Priority-lane rows created or unlocked before deadline support
                    // shipped have no deadline. Fail them safely into Standard.
                    ->orWhereNull('cards.priority_until')
                    ->orWhere('cards.priority_until', '<=', $now);
            });

            return;
        }

        $query
            ->where('cards.selection_policy', $lane === NewCardLane::LessonFollowup
                ? CardSelectionPolicy::ReviewSoon->value
                : CardSelectionPolicy::Sprinkled->value)
            ->where('cards.priority_until', '>', $now);
    }
}
