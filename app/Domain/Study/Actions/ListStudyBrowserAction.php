<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Study\Support\StudyBrowserCardAggregate;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ListStudyBrowserAction
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public const ALLOWED_SORT_FIELDS = [
        'created_on',
        'updated_on',
        'sort_field',
        'note_type',
        'card_count',
        'review_count',
    ];

    public const ALLOWED_SORT_DIRECTIONS = [
        'asc',
        'desc',
    ];

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
        $q = $this->normalizeSearchQuery($q);
        $noteType = $this->normalizeNoteTypeFilter($noteType);
        $cardType = $cardType === null ? null : CardType::fromFilter($cardType);
        $queueState = $queueState === null ? null : CardStudyStatus::fromFilter($queueState);
        $sortField = $this->normalizeSortField($sortField);
        $sortDirection = $this->normalizeSortDirection($sortDirection);
        $limit = $this->normalizeLimit($limit);
        $offset = $this->decodeOffsetCursor($cursor);
        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'Study browser');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'Study browser');
        $effectiveSortField = $sortField ?? 'created_on';
        $effectiveSortDirection = $sortDirection ?? 'desc';

        if ($this->canPageWithSqlAggregate($effectiveSortField)) {
            return $this->handleWithPagedGroups(
                userId: $userId,
                q: $q,
                noteType: $noteType,
                cardType: $cardType,
                queueState: $queueState,
                sortField: $effectiveSortField,
                sortDirection: $effectiveSortDirection,
                offset: $offset,
                limit: $limit,
                courseId: $courseId,
                deckId: $deckId,
            );
        }

        // Text-like sorts depend on rendered display text from JSON payloads, so this compatibility path still materializes matching cards.
        $cards = $this->cardsForBrowser(
            userId: $userId,
            q: $q,
            noteType: $noteType,
            cardType: $cardType,
            queueState: $queueState,
            courseId: $courseId,
            deckId: $deckId,
        );

        if ($cards->isEmpty()) {
            return [
                'rows' => [],
                'total' => 0,
                'limit' => $limit,
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
            $effectiveSortField,
            $effectiveSortDirection,
        );
        $pageRows = array_slice($rows, $offset, $limit);
        $nextOffset = $offset + count($pageRows);
        $filterOptionRows = $this->canReuseLoadedCardsForFilterOptions($noteType, $cardType, $queueState)
            ? $this->filterOptionRowsFromCards($cards)
            : $this->filterOptionRows($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);

        return [
            'rows' => $pageRows,
            'total' => count($rows),
            'limit' => $limit,
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
    private function handleWithPagedGroups(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        string $sortField,
        string $sortDirection,
        int $offset,
        int $limit,
        ?string $courseId,
        ?string $deckId,
    ): array {
        $groupRows = $this->orderGroupQuery(
            $this->browserGroupQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId),
            $sortField,
            $sortDirection,
        )
            ->skip($offset)
            ->take($limit)
            ->get();
        $total = $this->totalFromGroupRows($groupRows);

        if ($total === 0 && $offset === 0) {
            return [
                'rows' => [],
                'total' => 0,
                'limit' => $limit,
                'nextCursor' => null,
                'filterOptions' => [
                    'noteTypes' => [],
                    'cardTypes' => [],
                    'queueStates' => [],
                ],
            ];
        }

        if ($total === 0) {
            // Offset cursors can point beyond the final page; count once so `total` stays stable.
            // The empty group collection below makes card hydration a no-op while facets still describe the result set.
            $total = (int) DB::query()
                ->fromSub($this->browserGroupQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId), 'study_browser_groups')
                ->count();
        }

        $cards = $this->cardsForBrowserGroups(
            userId: $userId,
            q: $q,
            noteType: $noteType,
            cardType: $cardType,
            queueState: $queueState,
            courseId: $courseId,
            deckId: $deckId,
            groupRows: $groupRows,
        );
        $rowsByNoteId = collect($this->rowsFromCards($cards))->keyBy('noteId');
        $pageRows = $groupRows
            // A concurrent delete between the group query and card hydration can leave a group without cards.
            // Cursor advancement still follows the original group page so clients do not replay skipped groups.
            ->map(fn (object $group): ?array => $rowsByNoteId->get($this->noteIdFromGroupRow($group)))
            ->filter()
            ->values()
            ->all();
        $nextOffset = $offset + $groupRows->count();
        $filterOptionRows = $this->filterOptionRows($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);

        return [
            'rows' => $pageRows,
            'total' => $total,
            'limit' => $limit,
            'nextCursor' => $nextOffset < $total ? $this->encodeOffsetCursor($nextOffset) : null,
            'filterOptions' => [
                'noteTypes' => $this->filterNoteTypes($filterOptionRows),
                'cardTypes' => $this->filterCardTypes($filterOptionRows),
                'queueStates' => $this->filterQueueStates($filterOptionRows),
            ],
        ];
    }

    private function normalizeSearchQuery(?string $q): ?string
    {
        return CardSearchText::normalizeQuery($q);
    }

    private function normalizeNoteTypeFilter(?string $noteType): ?string
    {
        if ($noteType === null) {
            return null;
        }

        $noteType = trim($noteType);

        if ($noteType === '') {
            throw new InvalidArgumentException('Study browser noteType filter must not be blank when provided.');
        }

        return $noteType;
    }

    private function normalizeSortField(?string $sortField): ?string
    {
        if ($sortField === null) {
            return null;
        }

        $sortField = strtolower(trim($sortField));

        if (! in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
            throw new InvalidArgumentException(
                'Study browser sortField must be one of: '.implode(', ', self::ALLOWED_SORT_FIELDS).'.',
            );
        }

        return $sortField;
    }

    private function normalizeSortDirection(?string $sortDirection): ?string
    {
        if ($sortDirection === null) {
            return null;
        }

        $sortDirection = strtolower(trim($sortDirection));

        if (! in_array($sortDirection, self::ALLOWED_SORT_DIRECTIONS, true)) {
            throw new InvalidArgumentException(
                'Study browser sortDirection must be one of: '.implode(', ', self::ALLOWED_SORT_DIRECTIONS).'.',
            );
        }

        return $sortDirection;
    }

    private function normalizeLimit(?int $limit): int
    {
        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit must be an integer between 1 and '.self::MAX_LIMIT.'.');
        }

        return $limit;
    }

    /**
     * @return Collection<int, Card>
     */
    private function cardsForBrowser(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
    ): Collection {
        $baseQuery = $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);

        // Mirror the outer filters so the review aggregate scans only stats for visible browser cards.
        $matchingCardIds = (clone $baseQuery)
            ->select('cards.id')
            ->toBase();

        return $this->cardsWithReviewCounts($baseQuery, $matchingCardIds);
    }

    /**
     * @return Builder<Card>
     */
    private function browserCardQuery(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
    ): Builder {
        return $this->applyBrowserCardFilters(
            Card::query()->ownedByActiveDeck($userId),
            $q,
            $noteType,
            $cardType,
            $queueState,
            $courseId,
            $deckId,
        );
    }

    private function browserGroupQuery(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
    ): QueryBuilder {
        $baseQuery = $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);
        $matchingCardIds = (clone $baseQuery)
            ->select('cards.id')
            ->toBase();

        return $baseQuery
            ->leftJoinSub(
                $this->reviewCountSubquery($matchingCardIds),
                'review_event_stats',
                fn (JoinClause $join) => $join->on('review_event_stats.card_id', '=', 'cards.id'),
            )
            ->select([
                'cards.source_note_id',
            ])
            ->selectRaw('CASE WHEN cards.source_note_id IS NULL THEN cards.id ELSE NULL END AS unsourced_card_id')
            ->selectRaw('MIN(cards.created_at) AS created_on')
            ->selectRaw('MAX(cards.updated_at) AS updated_on')
            ->selectRaw('COUNT(cards.id) AS card_count')
            ->selectRaw('COALESCE(SUM(COALESCE(review_event_stats.review_events_count, 0)), 0) AS review_count')
            ->selectRaw('COUNT(*) OVER() AS total_rows')
            ->groupBy('cards.source_note_id')
            ->groupByRaw('CASE WHEN cards.source_note_id IS NULL THEN cards.id ELSE NULL END')
            ->toBase();
    }

    private function orderGroupQuery(QueryBuilder $query, string $sortField, string $sortDirection): QueryBuilder
    {
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';
        $sortColumn = match ($sortField) {
            'updated_on' => 'updated_on',
            'card_count' => 'card_count',
            'review_count' => 'review_count',
            default => 'created_on',
        };

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('source_note_id', $direction)
            ->orderBy('unsourced_card_id', $direction);
    }

    /**
     * @param  Collection<int, object{source_note_id: int|string|null, unsourced_card_id: string|null}>  $groupRows
     * @return Collection<int, Card>
     */
    private function cardsForBrowserGroups(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
        Collection $groupRows,
    ): Collection {
        $sourceNoteIds = $groupRows
            ->pluck('source_note_id')
            ->filter(fn (mixed $noteId): bool => $noteId !== null)
            ->map(fn (mixed $noteId): int => (int) $noteId)
            ->unique()
            ->values()
            ->all();
        $unsourcedCardIds = $groupRows
            ->pluck('unsourced_card_id')
            ->filter(fn (mixed $cardId): bool => is_string($cardId) && $cardId !== '')
            ->unique()
            ->values()
            ->all();

        if ($sourceNoteIds === [] && $unsourcedCardIds === []) {
            return new Collection;
        }

        $query = $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId)
            ->where(function (Builder $query) use ($sourceNoteIds, $unsourcedCardIds): void {
                if ($sourceNoteIds !== []) {
                    $query->whereIn('cards.source_note_id', $sourceNoteIds);
                }

                if ($unsourcedCardIds !== []) {
                    if ($sourceNoteIds === []) {
                        $query->where(function (Builder $query) use ($unsourcedCardIds): void {
                            $query
                                ->whereNull('cards.source_note_id')
                                ->whereIn('cards.id', $unsourcedCardIds);
                        });
                    } else {
                        $query->orWhere(function (Builder $query) use ($unsourcedCardIds): void {
                            $query
                                ->whereNull('cards.source_note_id')
                                ->whereIn('cards.id', $unsourcedCardIds);
                        });
                    }
                }
            });

        return $this->cardsWithReviewCounts($query);
    }

    /**
     * @param  Builder<Card>  $query
     * @return Collection<int, Card>
     */
    private function cardsWithReviewCounts(Builder $query, ?QueryBuilder $matchingCardIds = null): Collection
    {
        $matchingCardIds ??= (clone $query)
            ->select('cards.id')
            ->toBase();

        return $query
            ->leftJoinSub(
                $this->reviewCountSubquery($matchingCardIds),
                'review_event_stats',
                fn (JoinClause $join) => $join->on('review_event_stats.card_id', '=', 'cards.id'),
            )
            // Keep this projection in sync with rowsFromCards(), displayTextFor(), and queueStateSummaryValue().
            ->select([
                'cards.id',
                'cards.front_text',
                'cards.card_type',
                'cards.prompt_json',
                'cards.answer_json',
                'cards.study_status',
                'cards.source_kind',
                'cards.source_note_id',
                'cards.source_notetype_name',
                'cards.source_template_ord',
                'cards.created_at',
                'cards.updated_at',
            ])
            ->selectRaw('coalesce(review_event_stats.review_events_count, 0) as review_events_count')
            // NULL marks cards with no reviews; groupLastReviewedAt filters those before maxing the group.
            ->addSelect('review_event_stats.review_events_max_reviewed_at')
            ->orderBy('cards.source_note_id')
            ->orderBy('cards.source_template_ord')
            ->orderBy('cards.created_at')
            ->orderBy('cards.id')
            ->get();
    }

    private function noteIdFromGroupRow(object $group): string
    {
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
        $total = $groupRows->first()?->total_rows ?? null;

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return Collection<int, object{facet: string, value: string|null}>
     */
    private function filterOptionRows(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
    ): Collection {
        $noteTypes = $this->filterOptionQuery($userId, $q, null, $cardType, $queueState, $courseId, $deckId, 'note_type', 'cards.source_notetype_name');
        $cardTypes = $this->filterOptionQuery($userId, $q, $noteType, null, $queueState, $courseId, $deckId, 'card_type', 'cards.card_type');
        $queueStates = $this->filterOptionQuery($userId, $q, $noteType, $cardType, null, $courseId, $deckId, 'queue_state', 'cards.study_status');

        return $noteTypes
            ->union($cardTypes)
            ->union($queueStates)
            ->orderBy('facet')
            ->orderBy('value')
            ->get();
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

    private function filterOptionQuery(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
        string $facet,
        string $column,
    ): QueryBuilder {
        if (! in_array($column, ['cards.source_notetype_name', 'cards.card_type', 'cards.study_status'], true)) {
            throw new InvalidArgumentException('Study browser filter option column is invalid.');
        }

        // $column is a trusted literal column reference from filterOptionRows(); never pass request input here.
        return $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId)
            ->select(DB::raw($column.' as value'))
            ->selectRaw('? as facet', [$facet])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->toBase();
    }

    private function applyBrowserCardFilters(
        Builder $query,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
    ): Builder {
        return $query
            ->when($noteType !== null, fn ($query) => $query->where('cards.source_notetype_name', $noteType))
            ->when($cardType !== null, fn ($query) => $query->where('cards.card_type', $cardType->value))
            ->when($queueState !== null, fn ($query) => $query->where('cards.study_status', $queueState->value))
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId))
            ->when($q !== null, fn ($query) => $query->whereRaw(
                "lower(coalesce(cards.search_text, '')) like ? escape ?",
                [CardSearchText::likePattern($q), '\\'],
            ));
    }

    private function reviewCountSubquery(QueryBuilder $matchingCardIds): QueryBuilder
    {
        return DB::table('card_review_events')
            ->select('card_id')
            ->selectRaw('count(*) as review_events_count')
            ->selectRaw('max(reviewed_at) as review_events_max_reviewed_at')
            ->whereIn('card_id', $matchingCardIds)
            ->groupBy('card_id');
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
                    'selectedCardId' => (string) $firstCard->id,
                    'displayText' => $this->displayTextFor($firstCard),
                    'noteTypeName' => $firstCard->source_notetype_name,
                    'sourceKind' => StudyBrowserCardAggregate::sourceKindFor($firstCard),
                    'cardCount' => $group->count(),
                    'reviewCount' => StudyBrowserCardAggregate::reviewCount($group),
                    'lastReviewedAt' => StudyBrowserCardAggregate::lastReviewedAt($group),
                    'queueSummary' => $queueSummary,
                    'createdAt' => StudyBrowserCardAggregate::earliestTimestamp($group, 'created_at'),
                    'updatedAt' => StudyBrowserCardAggregate::latestTimestamp($group, 'updated_at'),
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

    private function decodeOffsetCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $payload = self::decodeCursorPayload($cursor);

        return $payload['offset'];
    }

    /**
     * @return array{offset: int}
     */
    public static function decodeCursorPayload(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($payload) || ! isset($payload['offset']) || ! is_int($payload['offset']) || $payload['offset'] < 0) {
            throw new InvalidArgumentException('Study browser cursor is invalid.');
        }

        return ['offset' => $payload['offset']];
    }
}
