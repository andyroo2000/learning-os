<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use App\Support\DateTime\ServerTimestamp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

class ListStudyBrowserAction
{
    private const SOURCE_KIND_NATIVE = 'native';

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
    ): array {
        $q = $this->normalizeSearchQuery($q);
        $noteType = $this->normalizeNoteTypeFilter($noteType);
        $cardType = $cardType === null ? null : CardType::fromFilter($cardType);
        $queueState = $queueState === null ? null : CardStudyStatus::fromFilter($queueState);
        $sortField = $this->normalizeSortField($sortField);
        $sortDirection = $this->normalizeSortDirection($sortDirection);
        $limit = $this->normalizeLimit($limit);
        $offset = $this->decodeOffsetCursor($cursor);

        // Browser rows are note aggregates with derived counts, so this compatibility slice materializes matching cards before sorting and slicing.
        $cards = $this->cardsForBrowser(
            userId: $userId,
            q: $q,
            noteType: $noteType,
            cardType: $cardType,
            queueState: $queueState,
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
            $sortField ?? 'created_on',
            $sortDirection ?? 'desc',
        );
        $pageRows = array_slice($rows, $offset, $limit);
        $nextOffset = $offset + count($pageRows);
        $filterOptionRows = $this->canReuseLoadedCardsForFilterOptions($noteType, $cardType, $queueState)
            ? $this->filterOptionRowsFromCards($cards)
            : $this->filterOptionRows($userId, $q, $noteType, $cardType, $queueState);

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
    ): Collection {
        $baseQuery = $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState);

        // Mirror the outer filters so the review aggregate scans only stats for visible browser cards.
        $matchingCardIds = (clone $baseQuery)
            ->select('cards.id')
            ->toBase();

        return $baseQuery
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
            ->addSelect('review_event_stats.review_events_max_reviewed_at')
            ->orderBy('cards.source_note_id')
            ->orderBy('cards.source_template_ord')
            ->orderBy('cards.created_at')
            ->orderBy('cards.id')
            ->get();
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
    ): Builder {
        return $this->applyBrowserCardFilters(
            Card::query()->ownedByActiveDeck($userId),
            $q,
            $noteType,
            $cardType,
            $queueState,
        );
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
    ): Collection {
        $noteTypes = $this->filterOptionQuery($userId, $q, null, $cardType, $queueState, 'note_type', 'cards.source_notetype_name');
        $cardTypes = $this->filterOptionQuery($userId, $q, $noteType, null, $queueState, 'card_type', 'cards.card_type');
        $queueStates = $this->filterOptionQuery($userId, $q, $noteType, $cardType, null, 'queue_state', 'cards.study_status');

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
        string $facet,
        string $column,
    ): QueryBuilder {
        if (! in_array($column, ['cards.source_notetype_name', 'cards.card_type', 'cards.study_status'], true)) {
            throw new InvalidArgumentException('Study browser filter option column is invalid.');
        }

        // $column is a trusted literal column reference from filterOptionRows(); never pass request input here.
        return $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState)
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
    ): Builder {
        return $query
            ->when($noteType !== null, fn ($query) => $query->where('cards.source_notetype_name', $noteType))
            ->when($cardType !== null, fn ($query) => $query->where('cards.card_type', $cardType->value))
            ->when($queueState !== null, fn ($query) => $query->where('cards.study_status', $queueState->value))
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
            ->groupBy(fn (Card $card) => $this->noteIdFor($card))
            ->map(function (Collection $group, string $noteId): array {
                /** @var Card $firstCard */
                $firstCard = $group->first();
                $queueSummary = [];
                $reviewCount = 0;

                foreach ($group as $card) {
                    $state = $this->queueStateSummaryValue($card);
                    $queueSummary[$state] = ($queueSummary[$state] ?? 0) + 1;
                    $reviewCount += (int) ($card->getAttribute('review_events_count') ?? 0);
                }

                ksort($queueSummary);

                return [
                    'noteId' => $noteId,
                    'selectedCardId' => $firstCard->id,
                    'displayText' => $this->displayTextFor($firstCard),
                    'noteTypeName' => $firstCard->source_notetype_name,
                    'sourceKind' => $this->sourceKindFor($firstCard),
                    'cardCount' => $group->count(),
                    'reviewCount' => $reviewCount,
                    'lastReviewedAt' => $this->groupLastReviewedAt($group),
                    'queueSummary' => $queueSummary,
                    'createdAt' => $this->groupTimestamp($group, 'created_at', latest: false),
                    'updatedAt' => $this->groupTimestamp($group, 'updated_at', latest: true),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Card>  $group
     */
    private function groupTimestamp(Collection $group, string $attribute, bool $latest): string
    {
        $timestamps = $group
            ->map(fn (Card $card): ?string => ServerTimestamp::toJson($card->getAttribute($attribute)))
            ->filter();

        $timestamp = $latest ? $timestamps->max() : $timestamps->min();

        return is_string($timestamp)
            ? $timestamp
            : throw new UnexpectedValueException("Study browser {$attribute} timestamp is missing or invalid.");
    }

    /**
     * @param  Collection<int, Card>  $group
     */
    private function groupLastReviewedAt(Collection $group): ?string
    {
        return $group
            ->map(fn (Card $card): ?string => $this->lastReviewedAt($card->getAttribute('review_events_max_reviewed_at')))
            ->filter()
            ->max();
    }

    private function lastReviewedAt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface || is_string($value)) {
            return ServerTimestamp::toJson($value)
                ?? throw new UnexpectedValueException('Study browser review aggregate is not a valid timestamp.');
        }

        throw new UnexpectedValueException('Study browser review aggregate has an unexpected timestamp type.');
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

    private function noteIdFor(Card $card): string
    {
        return $card->source_note_id === null ? (string) $card->id : (string) $card->source_note_id;
    }

    private function displayTextFor(Card $card): string
    {
        return StudyBrowserCardDisplay::displayTextFor($card);
    }

    private function sourceKindFor(Card $card): string
    {
        return is_string($card->source_kind) && $card->source_kind !== ''
            ? $card->source_kind
            : self::SOURCE_KIND_NATIVE;
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
