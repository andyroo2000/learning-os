<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;

final class ListStudyLearningItemsAction
{
    private const RETIRED_STAGE_STATUS = 'retired';

    /**
     * @return array{
     *     items: Collection<int, array{
     *         id: string,
     *         groupId: ?string,
     *         representativeCard: Card,
     *         currentStageNumber: ?int,
     *         stageCount: int,
     *         cardCount: int,
     *         retiredStageCount: int,
     *         transferDemonstrated: bool,
     *         stages: list<array{number: ?int, status: ?string, cardCount: int, representativeCard: Card}>
     *     }>,
     *     nextCursor: ?Cursor
     * }
     */
    public function handle(
        int $userId,
        ?CursorPageSize $pageSize = null,
        ?string $q = null,
    ): array {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $searchPattern = $q === null ? null : CardSearchText::likePattern($q);

        $representatives = Card::query()
            ->whereHas('deck', fn (Builder $query) => $query->where('user_id', $userId))
            ->where(function (Builder $query) use ($userId): void {
                $query
                    ->whereRaw("trim(coalesce(cards.variant_group_id, '')) = ''")
                    ->orWhere(function (Builder $query) use ($userId): void {
                        $query
                            ->whereRaw("trim(coalesce(cards.variant_group_id, '')) <> ''")
                            ->whereNotExists(function ($siblings) use ($userId): void {
                                $siblings
                                    ->selectRaw('1')
                                    ->from('cards as newer_group_card')
                                    ->join(
                                        'decks as newer_group_deck',
                                        'newer_group_deck.id',
                                        '=',
                                        'newer_group_card.deck_id',
                                    )
                                    ->whereColumn(
                                        'newer_group_card.variant_group_id',
                                        'cards.variant_group_id',
                                    )
                                    ->where('newer_group_deck.user_id', $userId)
                                    ->whereNull('newer_group_deck.deleted_at')
                                    ->whereNull('newer_group_card.deleted_at')
                                    ->where(function ($newer): void {
                                        $newer
                                            ->whereColumn('newer_group_card.created_at', '>', 'cards.created_at')
                                            ->orWhere(function ($sameCreatedAt): void {
                                                $sameCreatedAt
                                                    ->whereColumn(
                                                        'newer_group_card.created_at',
                                                        'cards.created_at',
                                                    )
                                                    ->whereColumn('newer_group_card.id', '>', 'cards.id');
                                            });
                                    });
                            });
                    });
            })
            ->when($searchPattern !== null, function (Builder $query) use ($searchPattern, $userId): void {
                $query->where(function (Builder $query) use ($searchPattern, $userId): void {
                    $query
                        ->where(function (Builder $ungrouped) use ($searchPattern): void {
                            $ungrouped
                                ->whereRaw("trim(coalesce(cards.variant_group_id, '')) = ''")
                                ->whereRaw(
                                    "lower(coalesce(cards.search_text, '')) like ? escape ?",
                                    [$searchPattern, '\\'],
                                );
                        })
                        ->orWhere(function (Builder $grouped) use ($searchPattern, $userId): void {
                            $grouped
                                ->whereRaw("trim(coalesce(cards.variant_group_id, '')) <> ''")
                                ->whereExists(function ($matches) use ($searchPattern, $userId): void {
                                    $matches
                                        ->selectRaw('1')
                                        ->from('cards as matching_group_card')
                                        ->join(
                                            'decks as matching_group_deck',
                                            'matching_group_deck.id',
                                            '=',
                                            'matching_group_card.deck_id',
                                        )
                                        ->whereColumn(
                                            'matching_group_card.variant_group_id',
                                            'cards.variant_group_id',
                                        )
                                        ->where('matching_group_deck.user_id', $userId)
                                        ->whereNull('matching_group_deck.deleted_at')
                                        ->whereNull('matching_group_card.deleted_at')
                                        ->whereRaw(
                                            "lower(coalesce(matching_group_card.search_text, '')) like ? escape ?",
                                            [$searchPattern, '\\'],
                                        );
                                });
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());

        /** @var Collection<int, Card> $representativeCards */
        $representativeCards = collect($representatives->items());
        $groupIds = $representativeCards
            ->map(fn (Card $card): ?string => $this->groupId($card))
            ->filter()
            ->unique()
            ->values();

        $groupCards = $groupIds->isEmpty()
            ? collect()
            : Card::query()
                ->whereHas('deck', fn (Builder $query) => $query->where('user_id', $userId))
                ->whereIn('cards.variant_group_id', $groupIds->all())
                ->orderByRaw('CASE WHEN cards.variant_stage IS NULL THEN 1 ELSE 0 END')
                ->orderBy('cards.variant_stage')
                ->orderByRaw('LOWER(cards.id)')
                ->orderBy('cards.id')
                ->get()
                ->groupBy('variant_group_id');

        return [
            'items' => $representativeCards
                ->map(function (Card $representative) use ($groupCards): array {
                    $groupId = $this->groupId($representative);
                    /** @var Collection<int, Card> $cards */
                    $cards = $groupId === null
                        ? collect([$representative])
                        : $groupCards->get($groupId, collect());

                    // Keep the already-authorized representative visible if a concurrent
                    // delete removes the family between the representative and family reads.
                    if ($cards->isEmpty()) {
                        $cards = collect([$representative]);
                    }

                    return $this->learningItem($representative, $groupId, $cards);
                })
                ->values(),
            'nextCursor' => $representatives->nextCursor(),
        ];
    }

    private function groupId(Card $card): ?string
    {
        return is_string($card->variant_group_id) && trim($card->variant_group_id) !== ''
            ? $card->variant_group_id
            : null;
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return array{
     *     id: string,
     *     groupId: ?string,
     *     representativeCard: Card,
     *     currentStageNumber: ?int,
     *     stageCount: int,
     *     cardCount: int,
     *     retiredStageCount: int,
     *     transferDemonstrated: bool,
     *     stages: list<array{number: ?int, status: ?string, cardCount: int, representativeCard: Card}>
     * }
     */
    private function learningItem(Card $representative, ?string $groupId, Collection $cards): array
    {
        /** @var array<string, array{number: ?int, cards: Collection<int, Card>}> $stageBuckets */
        $stageBuckets = [];

        foreach ($cards as $card) {
            $number = is_int($card->variant_stage) && $card->variant_stage > 0
                ? $card->variant_stage
                : null;
            $key = $number === null ? 'unknown' : "stage:{$number}";

            $stageBuckets[$key] ??= [
                'number' => $number,
                'cards' => collect(),
            ];
            $stageBuckets[$key]['cards']->push($card);
        }

        $stages = collect($stageBuckets)
            ->sortBy(fn (array $stage): int => $stage['number'] ?? PHP_INT_MAX)
            ->map(function (array $stage): array {
                /** @var Collection<int, Card> $stageCards */
                $stageCards = $stage['cards'];

                return [
                    'number' => $stage['number'],
                    'status' => $this->stageStatus($stageCards),
                    'cardCount' => $stageCards->count(),
                    'representativeCard' => $stageCards->firstOrFail(),
                ];
            })
            ->values();

        $currentStageNumber = $stages
            ->where('status', VocabVariantStatus::Available->value)
            ->pluck('number')
            ->filter(fn (mixed $number): bool => is_int($number))
            ->max();
        $maxStageNumber = $stages
            ->pluck('number')
            ->filter(fn (mixed $number): bool => is_int($number))
            ->max();
        $transferDemonstrated = $groupId !== null
            && $stages->count() > 1
            && is_int($maxStageNumber)
            && $stages->every(function (array $stage) use ($maxStageNumber): bool {
                if (! is_int($stage['number'])) {
                    return false;
                }

                if ($stage['number'] === $maxStageNumber) {
                    return in_array($stage['status'], [
                        VocabVariantStatus::Available->value,
                        self::RETIRED_STAGE_STATUS,
                    ], true);
                }

                return $stage['status'] === self::RETIRED_STAGE_STATUS;
            });

        /** @var Card $displayCard */
        $displayCard = $stages->first()['representativeCard'] ?? $representative;

        return [
            'id' => $groupId === null
                ? 'card:'.$representative->clientId()
                : 'path:'.$groupId,
            'groupId' => $groupId,
            'representativeCard' => $displayCard,
            'currentStageNumber' => is_int($currentStageNumber) ? $currentStageNumber : null,
            'stageCount' => $stages->count(),
            'cardCount' => $cards->count(),
            'retiredStageCount' => $stages->where('status', self::RETIRED_STAGE_STATUS)->count(),
            'transferDemonstrated' => $transferDemonstrated,
            'stages' => $stages->all(),
        ];
    }

    /** @param Collection<int, Card> $cards */
    private function stageStatus(Collection $cards): ?string
    {
        if ($cards->isNotEmpty() && $cards->every(
            fn (Card $card): bool => $card->variant_status === VocabVariantStatus::Locked->value
                && $card->study_status === CardStudyStatus::Suspended,
        )) {
            return self::RETIRED_STAGE_STATUS;
        }

        $statuses = $cards
            ->pluck('variant_status')
            ->filter(fn (mixed $status): bool => is_string($status) && $status !== '')
            ->unique()
            ->values();

        foreach ([
            VocabVariantStatus::Available->value,
            VocabVariantStatus::Locked->value,
        ] as $status) {
            if ($statuses->containsStrict($status)) {
                return $status;
            }
        }

        return null;
    }
}
