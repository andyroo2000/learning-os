<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Study\Queries\StudyBrowserQuery;
use App\Domain\Study\Support\StudyBrowserCardAggregate;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Support\Collection;
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
        $cards = $this->browserQuery->cards(
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
            : $this->browserQuery->filterOptionRows($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);

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
        $groupRows = $this->browserQuery->groupPage(
            userId: $userId,
            q: $q,
            noteType: $noteType,
            cardType: $cardType,
            queueState: $queueState,
            courseId: $courseId,
            deckId: $deckId,
            sortField: $sortField,
            sortDirection: $sortDirection,
            offset: $offset,
            limit: $limit,
        );
        $groupCount = $groupRows->count();
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
            // Offset cursors can point beyond the final page; this rare path counts once so `total` stays stable.
            // The empty group collection below makes card hydration a no-op while facets still describe the result set.
            $total = $this->browserQuery->groupCount($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);
        }

        $cards = $this->browserQuery->cardsForGroups(
            userId: $userId,
            q: $q,
            noteType: $noteType,
            cardType: $cardType,
            queueState: $queueState,
            courseId: $courseId,
            deckId: $deckId,
            groupRows: $groupRows,
        );
        // rowsFromCards() and noteIdFromGroupRow() both string-cast IDs so numeric Anki note IDs
        // and ULID fallback note IDs address the same map keys after database hydration.
        $rowsByNoteId = collect($this->rowsFromCards($cards))->keyBy('noteId');
        $pageRows = $groupRows
            // A concurrent delete between the group query and card hydration can leave a group without cards.
            // Cursor advancement still follows the original group page so clients do not replay skipped groups.
            ->map(fn (object $group): ?array => $rowsByNoteId->get($this->noteIdFromGroupRow($group)))
            ->filter()
            ->values()
            ->all();
        $nextOffset = $offset + $groupCount;
        // Empty offset pages are terminal even if a concurrent insert lands before the fallback recount.
        $nextCursor = $groupCount > 0 && $nextOffset < $total ? $this->encodeOffsetCursor($nextOffset) : null;
        $filterOptionRows = $this->browserQuery->filterOptionRows($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId);

        return [
            'rows' => $pageRows,
            'total' => $total,
            'limit' => $limit,
            'nextCursor' => $nextCursor,
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
