<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Results\DailyAudioCardSelectionResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SelectDailyAudioPracticeCardsAction
{
    public const DEFAULT_SELECTION_LIMIT = 30;

    public const DEFAULT_CANDIDATE_POOL_SIZE = 80;

    private const NEWER_CANDIDATE_POOL_SIZE = 30;

    private const RECENTLY_INTRODUCED_DAYS = 14;

    private const RECENTLY_REVIEWED_DAYS = 3;

    private const DUE_WEIGHT = 140;

    private const LEARNING_WEIGHT = 75;

    private const NEW_WEIGHT = 55;

    private const LAPSE_WEIGHT = 15;

    private const MAX_LAPSE_WEIGHT = 60;

    private const RECENTLY_INTRODUCED_WEIGHT = 55;

    private const RECENTLY_REVIEWED_WEIGHT = 20;

    private const UNREVIEWED_WEIGHT = 15;

    private const MAX_REVIEW_COUNT_PENALTY = 20;

    private const NEWER_CARD_RESERVE_RATIO = 0.3;

    public function handle(
        int $userId,
        ?CarbonImmutable $now = null,
    ): DailyAudioCardSelectionResult {
        $now ??= CarbonImmutable::now();
        $candidates = $this->candidates($userId, $now);
        $reviewCounts = $this->reviewCounts($userId, $candidates);
        $ranked = $this->ranked($candidates, $reviewCounts, $now);
        $selected = $this->selected($ranked, $now);

        return new DailyAudioCardSelectionResult(
            cards: $selected,
            summary: [
                'totalCandidates' => $candidates->count(),
                'totalEligible' => $candidates->count(),
                'selectedCount' => $selected->count(),
                'dueCount' => $selected->filter(
                    fn (Card $card): bool => $card->due_at !== null && $card->due_at->lte($now),
                )->count(),
                'learningCount' => $selected->filter(
                    fn (Card $card): bool => in_array($card->study_status, [
                        CardStudyStatus::Learning,
                        CardStudyStatus::Relearning,
                    ], true),
                )->count(),
                'recentMissCount' => $selected->filter(
                    fn (Card $card): bool => (int) ($card->source_lapses ?? 0) > 0,
                )->count(),
            ],
        );
    }

    /**
     * @return Collection<int, Card>
     */
    private function candidates(int $userId, CarbonImmutable $now): Collection
    {
        $newer = $this->candidateQuery($userId)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where('cards.study_status', CardStudyStatus::New->value)
                    ->orWhere('cards.introduced_at', '>', $now->subDays(self::RECENTLY_INTRODUCED_DAYS));
            })
            ->orderByRaw(
                'CASE WHEN cards.study_status = ? THEN 0 ELSE 1 END',
                [CardStudyStatus::New->value],
            )
            ->tap(fn (Builder $query): Builder => $this->orderCandidates($query))
            ->limit(self::NEWER_CANDIDATE_POOL_SIZE)
            ->get();

        $remainingLimit = self::DEFAULT_CANDIDATE_POOL_SIZE - $newer->count();
        if ($remainingLimit === 0) {
            return $newer->values();
        }

        $remaining = $this->candidateQuery($userId)
            ->when(
                $newer->isNotEmpty(),
                fn (Builder $query): Builder => $query->whereNotIn('cards.id', $newer->modelKeys()),
            )
            ->tap(fn (Builder $query): Builder => $this->orderCandidates($query))
            ->limit($remainingLimit)
            ->get();

        return $newer->concat($remaining)->values();
    }

    /**
     * @return Builder<Card>
     */
    private function candidateQuery(int $userId): Builder
    {
        return Card::query()
            ->ownedByActiveDeck($userId)
            ->whereIn('cards.study_status', [
                CardStudyStatus::New->value,
                CardStudyStatus::Learning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Relearning->value,
            ])
            // ownedByActiveDeck() starts with cards.*; override that projection explicitly.
            ->select([
                'cards.id',
                'cards.convolab_id',
                'cards.card_type',
                'cards.study_status',
                'cards.due_at',
                'cards.introduced_at',
                'cards.last_reviewed_at',
                'cards.updated_at',
                'cards.source_lapses',
                'cards.source_deck_name',
                'cards.source_notetype_name',
                'cards.prompt_json',
                'cards.answer_json',
                'cards.convolab_note_raw_fields_json',
            ]);
    }

    /**
     * @param  Builder<Card>  $query
     * @return Builder<Card>
     */
    private function orderCandidates(Builder $query): Builder
    {
        return $query
            // Explicit null placement keeps the candidate pool identical on SQLite and Postgres.
            ->orderByRaw('CASE WHEN cards.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('cards.due_at')
            ->orderByDesc('cards.last_reviewed_at')
            ->orderByDesc('cards.introduced_at')
            ->orderByDesc('cards.updated_at')
            ->orderBy('cards.id');
    }

    /**
     * @param  Collection<int, Card>  $candidates
     * @return array<string, int>
     */
    private function reviewCounts(int $userId, Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        return CardReviewEvent::query()
            ->ownedByActiveCardDeck($userId)
            ->whereIn('card_id', $candidates->modelKeys())
            ->selectRaw('card_id, COUNT(*) AS review_count')
            ->groupBy('card_id')
            ->pluck('review_count', 'card_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int, Card>  $candidates
     * @param  array<string, int>  $reviewCounts
     * @return Collection<int, Card>
     */
    private function ranked(
        Collection $candidates,
        array $reviewCounts,
        CarbonImmutable $now,
    ): Collection {
        return $candidates
            ->sort(function (Card $left, Card $right) use ($reviewCounts, $now): int {
                $scoreComparison = $this->score(
                    $right,
                    $reviewCounts[(string) $right->id] ?? 0,
                    $now,
                ) <=> $this->score(
                    $left,
                    $reviewCounts[(string) $left->id] ?? 0,
                    $now,
                );
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $updatedComparison = $right->updated_at <=> $left->updated_at;

                return $updatedComparison !== 0
                    ? $updatedComparison
                    : strcmp((string) $left->id, (string) $right->id);
            })
            ->values();
    }

    private function score(Card $card, int $reviewCount, CarbonImmutable $now): int
    {
        $score = 0;

        if ($card->due_at !== null && $card->due_at->lte($now)) {
            $score += self::DUE_WEIGHT;
        }
        if (in_array($card->study_status, [
            CardStudyStatus::Learning,
            CardStudyStatus::Relearning,
        ], true)) {
            $score += self::LEARNING_WEIGHT;
        }
        if ($card->study_status === CardStudyStatus::New) {
            $score += self::NEW_WEIGHT;
        }

        $lapses = max(0, (int) ($card->source_lapses ?? 0));
        $score += min(self::MAX_LAPSE_WEIGHT, $lapses * self::LAPSE_WEIGHT);

        if ($this->isWithinDays($card->introduced_at, $now, self::RECENTLY_INTRODUCED_DAYS)) {
            $score += self::RECENTLY_INTRODUCED_WEIGHT;
        }
        if ($this->isWithinDays($card->last_reviewed_at, $now, self::RECENTLY_REVIEWED_DAYS)) {
            $score += self::RECENTLY_REVIEWED_WEIGHT;
        }
        if ($reviewCount === 0) {
            $score += self::UNREVIEWED_WEIGHT;
        }

        return $score - min(self::MAX_REVIEW_COUNT_PENALTY, $reviewCount);
    }

    /**
     * @param  Collection<int, Card>  $ranked
     * @return Collection<int, Card>
     */
    private function selected(Collection $ranked, CarbonImmutable $now): Collection
    {
        $newer = $ranked->filter(
            fn (Card $card): bool => $card->study_status === CardStudyStatus::New
                || $this->isWithinDays($card->introduced_at, $now, self::RECENTLY_INTRODUCED_DAYS),
        );
        $reservedCount = min(
            $newer->count(),
            (int) ceil(self::DEFAULT_SELECTION_LIMIT * self::NEWER_CARD_RESERVE_RATIO),
        );
        $selected = $newer->take($reservedCount)->values();
        $selectedIds = $selected->modelKeys();

        return $selected
            ->concat(
                $ranked
                    ->reject(fn (Card $card): bool => in_array($card->getKey(), $selectedIds, true))
                    ->take(self::DEFAULT_SELECTION_LIMIT - $selected->count()),
            )
            ->take(self::DEFAULT_SELECTION_LIMIT)
            ->values();
    }

    private function isWithinDays(mixed $timestamp, CarbonImmutable $now, int $days): bool
    {
        return $timestamp !== null
            && $timestamp->lte($now)
            && $timestamp->gt($now->subDays($days));
    }
}
