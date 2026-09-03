<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Queries\StudyBrowserQuery;
use App\Domain\Study\Support\StudyBrowserCardAggregate;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use App\Domain\Study\Support\StudyBrowserListCriteria;
use Illuminate\Support\Collection;

class ListStudyBrowserAction
{
    public const DEFAULT_LIMIT = StudyBrowserListCriteria::DEFAULT_LIMIT;

    public const MAX_LIMIT = StudyBrowserListCriteria::MAX_LIMIT;

    public const ALLOWED_SORT_FIELDS = StudyBrowserListCriteria::ALLOWED_SORT_FIELDS;

    public const ALLOWED_SORT_DIRECTIONS = StudyBrowserListCriteria::ALLOWED_SORT_DIRECTIONS;

    public function __construct(private readonly StudyBrowserQuery $browserQuery) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     limit: int,
     *     nextCursor: string|null,
     *     filterOptions: array{noteTypes: list<string>, cardTypes: list<string>, queueStates: list<string>}
     * }
     */
    public function handle(
        int $userId,
        ?string $q = null,
        ?string $noteType = null,
        CardType|string|null $cardType = null,
        CardStudyStatus|string|null $queueState = null,
        ?string $sortField = null,
        ?string $sortDirection = null,
        ?string $cursor = null,
        ?int $limit = null,
        ?string $courseId = null,
        ?string $deckId = null,
    ): array {
        $criteria = StudyBrowserListCriteria::fromInput([
            'userId' => $userId,
            'q' => $q,
            'noteType' => $noteType,
            'cardType' => $cardType,
            'queueState' => $queueState,
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
            'cursor' => $cursor,
            'limit' => $limit,
            'courseId' => $courseId,
            'deckId' => $deckId,
        ]);

        return $this->list($criteria);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     limit: int,
     *     nextCursor: string|null,
     *     filterOptions: array{noteTypes: list<string>, cardTypes: list<string>, queueStates: list<string>}
     * }
     */
    private function list(StudyBrowserListCriteria $criteria): array
    {
        if ($this->canPageWithSqlAggregate($criteria->sortField)) {
            return $this->handleWithPagedGroups($criteria);
        }

        return $this->handleWithLoadedCards($criteria);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     limit: int,
     *     nextCursor: string|null,
     *     filterOptions: array{noteTypes: list<string>, cardTypes: list<string>, queueStates: list<string>}
     * }
     */
    private function handleWithLoadedCards(StudyBrowserListCriteria $criteria): array
    {
        // Text-like sorts depend on rendered display text from JSON payloads, so this compatibility path still materializes matching cards.
        $cards = $this->browserQuery->cards(
            userId: $criteria->userId,
            q: $criteria->q,
            noteType: $criteria->noteType,
            cardType: $criteria->cardType,
            queueState: $criteria->queueState,
            courseId: $criteria->courseId,
            deckId: $criteria->deckId,
        );

        if ($cards->isEmpty()) {
            return [
                'rows' => [],
                'total' => 0,
                'limit' => $criteria->limit,
                'nextCursor' => null,
                'filterOptions' => [
                    'noteTypes' => [],
                    'cardTypes' => [],
                    'queueStates' => [],
                ],
            ];
        }

        $rows = $this->sortRows(
            $this->rowsFromCards($cards),
            $criteria->sortField,
            $criteria->sortDirection,
        );
        $pageRows = array_slice($rows, $criteria->offset, $criteria->limit);
        $nextOffset = $criteria->offset + count($pageRows);
        $filterOptionRows = $this->canReuseLoadedCardsForFilterOptions(
            $criteria->noteType,
            $criteria->cardType,
            $criteria->queueState,
        )
            ? $this->filterOptionRowsFromCards($cards)
            : $this->browserQuery->filterOptionRows(
                $criteria->userId,
                $criteria->q,
                $criteria->noteType,
                $criteria->cardType,
                $criteria->queueState,
                $criteria->courseId,
                $criteria->deckId,
            );

        return [
            'rows' => $pageRows,
            'total' => count($rows),
            'limit' => $criteria->limit,
            'nextCursor' => $nextOffset < count($rows) ? $this->encodeOffsetCursor($nextOffset) : null,
            'filterOptions' => [
                'noteTypes' => $this->filterNoteTypes($filterOptionRows),
                'cardTypes' => $this->filterCardTypes($filterOptionRows),
                'queueStates' => $this->filterQueueStates($filterOptionRows),
            ],
        ];
    }

    private function canPageWithSqlAggregate(string $sortField): bool
    {
        return in_array($sortField, ['created_on', 'updated_on', 'card_count', 'review_count'], true);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     limit: int,
     *     nextCursor: string|null,
     *     filterOptions: array{noteTypes: list<string>, cardTypes: list<string>, queueStates: list<string>}
     * }
     */
    private function handleWithPagedGroups(StudyBrowserListCriteria $criteria): array
    {
        $groupRows = $this->browserQuery->groupPage(
            userId: $criteria->userId,
            q: $criteria->q,
            noteType: $criteria->noteType,
            cardType: $criteria->cardType,
            queueState: $criteria->queueState,
            courseId: $criteria->courseId,
            deckId: $criteria->deckId,
            sortField: $criteria->sortField,
            sortDirection: $criteria->sortDirection,
            offset: $criteria->offset,
            limit: $criteria->limit,
        );
        $groupCount = $groupRows->count();
        $total = $this->totalFromGroupRows($groupRows);

        if ($total === 0 && $criteria->offset === 0) {
            return [
                'rows' => [],
                'total' => 0,
                'limit' => $criteria->limit,
                'nextCursor' => null,
                'filterOptions' => [
                    'noteTypes' => [],
                    'cardTypes' => [],
                    'queueStates' => [],
                ],
            ];
        }

        if ($total === 0) {
            // Offset cursors can point beyond the final page; this rare path counts once so `total` stays stable.
            // The empty group collection below makes card hydration a no-op while facets still describe the result set.
            $total = $this->browserQuery->groupCount(
                $criteria->userId,
                $criteria->q,
                $criteria->noteType,
                $criteria->cardType,
                $criteria->queueState,
                $criteria->courseId,
                $criteria->deckId,
            );
        }

        $pageRows = $this->pageRowsForGroups($criteria, $groupRows);
        $nextOffset = $criteria->offset + $groupCount;
        // Empty offset pages are terminal even if a concurrent insert lands before the fallback recount.
        $nextCursor = $groupCount > 0 && $nextOffset < $total ? $this->encodeOffsetCursor($nextOffset) : null;
        $filterOptionRows = $this->browserQuery->filterOptionRows(
            $criteria->userId,
            $criteria->q,
            $criteria->noteType,
            $criteria->cardType,
            $criteria->queueState,
            $criteria->courseId,
            $criteria->deckId,
        );

        return [
            'rows' => $pageRows,
            'total' => $total,
            'limit' => $criteria->limit,
            'nextCursor' => $nextCursor,
            'filterOptions' => [
                'noteTypes' => $this->filterNoteTypes($filterOptionRows),
                'cardTypes' => $this->filterCardTypes($filterOptionRows),
                'queueStates' => $this->filterQueueStates($filterOptionRows),
            ],
        ];
    }

    /**
     * @param  Collection<int, object>  $groupRows
     * @return list<array<string, mixed>>
     */
    private function pageRowsForGroups(StudyBrowserListCriteria $criteria, Collection $groupRows): array
    {
        $cards = $this->browserQuery->cardsForGroups(
            userId: $criteria->userId,
            q: $criteria->q,
            noteType: $criteria->noteType,
            cardType: $criteria->cardType,
            queueState: $criteria->queueState,
            courseId: $criteria->courseId,
            deckId: $criteria->deckId,
            groupRows: $groupRows,
        );
        // rowsFromCards() and noteIdFromGroupRow() both string-cast IDs so numeric Anki note IDs
        // and ULID fallback note IDs address the same map keys after database hydration.
        $rowsByNoteId = collect($this->rowsFromCards($cards))->keyBy('noteId');

        return $groupRows
            // A concurrent delete between the group query and card hydration can leave a group without cards.
            // Cursor advancement still follows the original group page so clients do not replay skipped groups.
            ->map(fn (object $group): ?array => $rowsByNoteId->get($this->noteIdFromGroupRow($group)))
            ->filter()
            ->values()
            ->all();
    }

    private function noteIdFromGroupRow(object $group): string
    {
        if (($group->convolab_note_id ?? null) !== null) {
            return (string) $group->convolab_note_id;
        }

        if (($group->source_note_id ?? null) !== null) {
            return (string) $group->source_note_id;
        }

        return (string) $group->unsourced_card_id;
    }

    /**
     * @param  Collection<int, object{total_rows?: int|string|null}>  $groupRows
     */
    private function totalFromGroupRows(Collection $groupRows): int
    {
        // COUNT(*) OVER() is identical on every returned group row; first() is enough for the page total.
        $total = $groupRows->first()?->total_rows ?? null;

        return is_numeric($total) ? (int) $total : 0;
    }

    private function canReuseLoadedCardsForFilterOptions(
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
    ): bool {
        return $noteType === null
            && $cardType === null
            && $queueState === null;
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return Collection<int, object{facet: string, value: string}>
     */
    private function filterOptionRowsFromCards(Collection $cards): Collection
    {
        return $cards
            ->flatMap(fn (Card $card): array => [
                (object) ['facet' => 'note_type', 'value' => $card->getRawOriginal('source_notetype_name')],
                (object) ['facet' => 'card_type', 'value' => $card->getRawOriginal('card_type')],
                (object) ['facet' => 'queue_state', 'value' => $card->getRawOriginal('study_status')],
            ])
            ->filter(fn (object $row): bool => is_string($row->value) && $row->value !== '')
            // Facet names are fixed literals and SQL text values cannot contain NUL, so this is collision-free.
            ->unique(fn (object $row): string => $row->facet."\0".$row->value)
            ->sort(fn (object $left, object $right): int => [$left->facet, $left->value] <=> [$right->facet, $right->value])
            ->values();
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return list<array<string, mixed>>
     */
    private function rowsFromCards(Collection $cards): array
    {
        return $cards
            ->groupBy(fn (Card $card) => StudyBrowserCardAggregate::noteIdFor($card))
            ->map(function (Collection $group, string $noteId): array {
                /** @var Card $firstCard */
                $firstCard = $group->first();
                $queueSummary = [];

                foreach ($group as $card) {
                    $state = $this->queueStateSummaryValue($card);
                    $queueSummary[$state] = ($queueSummary[$state] ?? 0) + 1;
                }

                ksort($queueSummary);

                return [
                    'noteId' => $noteId,
                    'selectedCardId' => $firstCard->clientId(),
                    'displayText' => $this->displayTextFor($firstCard),
                    'noteTypeName' => $firstCard->source_notetype_name,
                    'sourceKind' => StudyBrowserCardAggregate::sourceKindFor($firstCard),
                    'cardCount' => $group->count(),
                    'reviewCount' => StudyBrowserCardAggregate::reviewCount($group),
                    'lastReviewedAt' => StudyBrowserCardAggregate::lastReviewedAt($group),
                    'queueSummary' => $queueSummary,
                    'createdAt' => StudyBrowserCardAggregate::noteCreatedAt($group),
                    'updatedAt' => StudyBrowserCardAggregate::noteUpdatedAt($group),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sortField, string $sortDirection): array
    {
        $descending = $sortDirection !== 'asc';

        usort($rows, function (array $left, array $right) use ($sortField, $descending): int {
            $comparison = $this->sortValue($left, $sortField) <=> $this->sortValue($right, $sortField);

            if ($comparison === 0) {
                $comparison = $this->compareNoteIds($left['noteId'], $right['noteId']);
            }

            return $descending ? -$comparison : $comparison;
        });

        return $rows;
    }

    private function sortValue(array $row, string $sortField): string|int
    {
        return match ($sortField) {
            'updated_on' => (string) $row['updatedAt'],
            'sort_field' => mb_strtolower((string) $row['displayText']),
            'note_type' => mb_strtolower((string) ($row['noteTypeName'] ?? '')),
            'card_count' => (int) $row['cardCount'],
            'review_count' => (int) $row['reviewCount'],
            default => (string) $row['createdAt'],
        };
    }

    private function compareNoteIds(mixed $leftNoteId, mixed $rightNoteId): int
    {
        $left = (string) $leftNoteId;
        $right = (string) $rightNoteId;

        if (ctype_digit($left) && ctype_digit($right)) {
            return ((int) $left) <=> ((int) $right);
        }

        return $left <=> $right;
    }

    private function displayTextFor(Card $card): string
    {
        return StudyBrowserCardDisplay::displayTextFor($card);
    }

    private function queueStateSummaryValue(Card $card): string
    {
        $rawState = $card->getRawOriginal('study_status');

        if (is_string($rawState)) {
            return CardStudyStatus::tryFrom($rawState)?->value ?? CardStudyStatus::New->value;
        }

        return CardStudyStatus::New->value;
    }

    /**
     * @param  Collection<int, object{facet: string, value: string|null}>  $filterOptionRows
     * @return list<string>
     */
    private function filterNoteTypes(Collection $filterOptionRows): array
    {
        return $filterOptionRows
            ->where('facet', 'note_type')
            ->pluck('value')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object{facet: string, value: string|null}>  $filterOptionRows
     * @return list<string>
     */
    private function filterCardTypes(Collection $filterOptionRows): array
    {
        return $filterOptionRows
            ->where('facet', 'card_type')
            ->pluck('value')
            ->map(fn (mixed $cardType): ?string => $this->cardTypeFacetValue($cardType))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object{facet: string, value: string|null}>  $filterOptionRows
     * @return list<string>
     */
    private function filterQueueStates(Collection $filterOptionRows): array
    {
        return $filterOptionRows
            ->where('facet', 'queue_state')
            ->pluck('value')
            ->map(fn (mixed $queueState): ?string => $this->queueStateFacetValue($queueState))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function cardTypeFacetValue(mixed $cardType): ?string
    {
        if ($cardType instanceof CardType) {
            return $cardType->value;
        }

        if (is_string($cardType)) {
            return CardType::tryFrom($cardType)?->value;
        }

        return null;
    }

    private function queueStateFacetValue(mixed $queueState): ?string
    {
        if ($queueState instanceof CardStudyStatus) {
            return $queueState->value;
        }

        if (is_string($queueState)) {
            return CardStudyStatus::tryFrom($queueState)?->value;
        }

        return null;
    }

    private function encodeOffsetCursor(int $offset): string
    {
        // Offset cursors mirror the legacy browser contract; data changes between page requests can shift later pages.
        return rtrim(strtr(base64_encode(json_encode(['offset' => $offset], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{offset: int}
     */
    public static function decodeCursorPayload(string $cursor): array
    {
        return StudyBrowserListCriteria::decodeCursorPayload($cursor);
    }
}
