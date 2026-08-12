<?php

namespace App\Domain\Study\Queries;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StudyBrowserQuery
{
    private const SOURCE_NOTE_GROUP_SQL = 'CASE WHEN cards.convolab_note_id IS NULL THEN cards.source_note_id ELSE NULL END';

    private const UNSOURCED_CARD_GROUP_SQL = 'CASE WHEN cards.convolab_note_id IS NULL AND cards.source_note_id IS NULL THEN cards.id ELSE NULL END';

    /**
     * @return Collection<int, Card>
     */
    public function cards(
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
     * @return Collection<int, object{
     *     convolab_note_id: string|null,
     *     source_note_id: int|string|null,
     *     unsourced_card_id: string|null,
     *     total_rows: int|string|null
     * }>
     */
    public function groupPage(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
        string $sortField,
        string $sortDirection,
        int $offset,
        int $limit,
    ): Collection {
        return $this->orderGroupQuery(
            $this->browserGroupQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId),
            $sortField,
            $sortDirection,
        )
            ->skip($offset)
            ->take($limit)
            ->get();
    }

    public function groupCount(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
    ): int {
        return (int) DB::query()
            ->fromSub(
                $this->browserGroupQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId),
                'study_browser_groups',
            )
            ->count();
    }

    /**
     * @param  Collection<int, object{convolab_note_id: string|null, source_note_id: int|string|null, unsourced_card_id: string|null}>  $groupRows
     * @return Collection<int, Card>
     */
    public function cardsForGroups(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
        ?string $courseId,
        ?string $deckId,
        Collection $groupRows,
    ): Collection {
        $convoLabNoteIds = $groupRows
            ->pluck('convolab_note_id')
            ->filter(fn (mixed $noteId): bool => is_string($noteId) && $noteId !== '')
            ->unique()
            ->values()
            ->all();
        $sourceNoteIds = $groupRows
            ->filter(fn (object $group): bool => ($group->convolab_note_id ?? null) === null)
            ->pluck('source_note_id')
            ->filter(fn (mixed $noteId): bool => $noteId !== null)
            ->map(fn (mixed $noteId): int => (int) $noteId)
            ->unique()
            ->values()
            ->all();
        $unsourcedCardIds = $groupRows
            ->pluck('unsourced_card_id')
            ->filter(fn (mixed $cardId): bool => $cardId !== null && $cardId !== '')
            ->map(fn (mixed $cardId): string => (string) $cardId)
            ->unique()
            ->values()
            ->all();

        if ($convoLabNoteIds === [] && $sourceNoteIds === [] && $unsourcedCardIds === []) {
            return new Collection;
        }

        $query = $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState, $courseId, $deckId)
            ->where(function (Builder $query) use ($convoLabNoteIds, $sourceNoteIds, $unsourcedCardIds): void {
                if ($convoLabNoteIds !== []) {
                    $query->whereIn('cards.convolab_note_id', $convoLabNoteIds);
                }

                if ($sourceNoteIds !== []) {
                    $matchSourceNotes = fn (Builder $query) => $query
                        ->whereNull('cards.convolab_note_id')
                        ->whereIn('cards.source_note_id', $sourceNoteIds);

                    if ($convoLabNoteIds === []) {
                        $query->where($matchSourceNotes);
                    } else {
                        $query->orWhere($matchSourceNotes);
                    }
                }

                if ($unsourcedCardIds !== []) {
                    $matchUnsourcedCards = function (Builder $query) use ($unsourcedCardIds): void {
                        $query
                            ->whereNull('cards.convolab_note_id')
                            ->whereNull('cards.source_note_id')
                            ->whereIn('cards.id', $unsourcedCardIds);
                    };

                    if ($convoLabNoteIds === [] && $sourceNoteIds === []) {
                        $query->where($matchUnsourcedCards);
                    } else {
                        $query->orWhere($matchUnsourcedCards);
                    }
                }
            });

        return $this->cardsWithReviewCounts($query);
    }

    /**
     * @return Collection<int, object{facet: string, value: string|null}>
     */
    public function filterOptionRows(
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
            ->select('cards.convolab_note_id')
            // The canonical ConvoLab note ID owns copied-note identity; legacy source IDs only group uncopied cards.
            ->selectRaw(self::SOURCE_NOTE_GROUP_SQL.' AS source_note_id')
            ->selectRaw(self::UNSOURCED_CARD_GROUP_SQL.' AS unsourced_card_id')
            ->selectRaw('MIN(COALESCE(cards.convolab_note_created_at, cards.created_at)) AS created_on')
            ->selectRaw('MAX(COALESCE(cards.convolab_note_updated_at, cards.updated_at)) AS updated_on')
            ->selectRaw('COUNT(cards.id) AS card_count')
            ->selectRaw('COALESCE(SUM(COALESCE(review_event_stats.review_events_count, 0)), 0) AS review_count')
            ->selectRaw('COUNT(*) OVER() AS total_rows')
            ->groupBy('cards.convolab_note_id')
            ->groupByRaw(self::SOURCE_NOTE_GROUP_SQL)
            ->groupByRaw(self::UNSOURCED_CARD_GROUP_SQL)
            ->toBase();
    }

    private function orderGroupQuery(QueryBuilder $query, string $sortField, string $sortDirection): QueryBuilder
    {
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';
        $sortColumn = match ($sortField) {
            'created_on' => 'created_on',
            'updated_on' => 'updated_on',
            'card_count' => 'card_count',
            'review_count' => 'review_count',
            default => throw new InvalidArgumentException("Unsupported aggregate sort field [{$sortField}]."),
        };

        return $query
            ->orderBy($sortColumn, $direction)
            // Avoid database-specific NULL ordering when sourced and unsourced rows tie on the sort value.
            ->orderByRaw('CASE WHEN convolab_note_id IS NULL THEN 1 ELSE 0 END asc')
            ->orderBy('convolab_note_id', $direction)
            ->orderByRaw('CASE WHEN ('.self::SOURCE_NOTE_GROUP_SQL.') IS NULL THEN 1 ELSE 0 END asc')
            ->orderByRaw(self::SOURCE_NOTE_GROUP_SQL." {$direction}")
            ->orderBy('unsourced_card_id', $direction);
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
            // Browser row aggregation intentionally receives only this bounded projection.
            ->select([
                'cards.id',
                'cards.convolab_id',
                'cards.convolab_note_id',
                'cards.convolab_note_created_at',
                'cards.convolab_note_updated_at',
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
            // NULL marks cards with no reviews; the row aggregate filters those before maxing the group.
            ->addSelect('review_event_stats.review_events_max_reviewed_at')
            ->orderBy('cards.source_note_id')
            ->orderBy('cards.source_template_ord')
            ->orderBy('cards.created_at')
            ->orderBy('cards.id')
            ->get();
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
}
