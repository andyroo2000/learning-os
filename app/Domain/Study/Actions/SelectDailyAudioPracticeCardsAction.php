<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Results\DailyAudioCardSelectionResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SelectDailyAudioPracticeCardsAction
{
    public const DEFAULT_SELECTION_LIMIT = 30;

    public const DEFAULT_CANDIDATE_POOL_SIZE = 80;

    private const RECENTLY_INTRODUCED_DAYS = 14;

    private const RECENTLY_REVIEWED_DAYS = 3;

    public function handle(
        int $userId,
        ?CarbonImmutable $now = null,
    ): DailyAudioCardSelectionResult {
        $now ??= CarbonImmutable::now();
        $candidates = $this->candidates($userId);
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
    private function candidates(int $userId): Collection
    {
        return Card::query()
            ->ownedByActiveDeck($userId)
            ->whereIn('cards.study_status', [
                CardStudyStatus::New->value,
                CardStudyStatus::Learning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Relearning->value,
            ])
            // Explicit null placement keeps the candidate pool identical on SQLite and Postgres.
            ->orderByRaw('CASE WHEN cards.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('cards.due_at')
            ->orderByDesc('cards.last_reviewed_at')
            ->orderByDesc('cards.introduced_at')
            ->orderByDesc('cards.updated_at')
            ->orderBy('cards.id')
            ->limit(self::DEFAULT_CANDIDATE_POOL_SIZE)
            ->get([
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
            $score += 140;
        }
        if (in_array($card->study_status, [
            CardStudyStatus::Learning,
            CardStudyStatus::Relearning,
        ], true)) {
            $score += 75;
        }
        if ($card->study_status === CardStudyStatus::New) {
            $score += 55;
        }

        $lapses = max(0, (int) ($card->source_lapses ?? 0));
        $score += min(60, $lapses * 15);

        if ($this->isWithinDays($card->introduced_at, $now, self::RECENTLY_INTRODUCED_DAYS)) {
            $score += 55;
        }
        if ($this->isWithinDays($card->last_reviewed_at, $now, self::RECENTLY_REVIEWED_DAYS)) {
            $score += 20;
        }
        if ($reviewCount === 0) {
            $score += 15;
        }

        return $score - min(20, $reviewCount);
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
            (int) ceil(self::DEFAULT_SELECTION_LIMIT * 0.3),
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
