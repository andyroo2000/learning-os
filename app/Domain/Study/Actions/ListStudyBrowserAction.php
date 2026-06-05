<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ListStudyBrowserAction
{
    public const DEFAULT_LIMIT = 100;

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
        $limit = max(1, min(self::MAX_LIMIT, $limit ?? self::DEFAULT_LIMIT));
        $offset = $this->decodeOffsetCursor($cursor);

        // Browser rows are note aggregates with derived counts, so this compatibility slice materializes matching cards before sorting and slicing.
        $cards = $this->cardsForBrowser(
            userId: $userId,
            q: $q,
            noteType: $noteType,
            cardType: $cardType,
            queueState: $queueState,
        );
        $reviewCounts = $this->reviewCountsByCard($cards->pluck('id'));
        $rows = $this->sortRows(
            $this->rowsFromCards($cards, $reviewCounts),
            $sortField ?? 'created_on',
            $sortDirection ?? 'desc',
        );
        $pageRows = array_slice($rows, $offset, $limit);
        $nextOffset = $offset + count($pageRows);

        return [
            'rows' => $pageRows,
            'total' => count($rows),
            'limit' => $limit,
            'nextCursor' => $nextOffset < count($rows) ? $this->encodeOffsetCursor($nextOffset) : null,
            'filterOptions' => [
                'noteTypes' => $this->filterNoteTypes($userId, $q, $cardType, $queueState),
                'cardTypes' => $this->filterCardTypes($userId, $q, $noteType, $queueState),
                'queueStates' => $this->filterQueueStates($userId, $q, $noteType, $cardType),
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
        return $this->browserCardQuery($userId, $q, $noteType, $cardType, $queueState)
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
        return Card::query()
            ->ownedByActiveDeck($userId)
            ->when($noteType !== null, fn ($query) => $query->where('cards.source_notetype_name', $noteType))
            ->when($cardType !== null, fn ($query) => $query->where('cards.card_type', $cardType->value))
            ->when($queueState !== null, fn ($query) => $query->where('cards.study_status', $queueState->value))
            ->when($q !== null, fn ($query) => $query->whereRaw(
                "lower(coalesce(cards.search_text, '')) like ? escape ?",
                [CardSearchText::likePattern($q), '\\'],
            ));
    }

    /**
     * @param  Collection<int, string>  $cardIds
     * @return array<string, int>
     */
    private function reviewCountsByCard(Collection $cardIds): array
    {
        if ($cardIds->isEmpty()) {
            return [];
        }

        return CardReviewEvent::query()
            ->whereIn('card_id', $cardIds->all())
            ->selectRaw('card_id, count(*) as review_count')
            ->groupBy('card_id')
            ->pluck('review_count', 'card_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @param  array<string, int>  $reviewCounts
     * @return list<array<string, mixed>>
     */
    private function rowsFromCards(Collection $cards, array $reviewCounts): array
    {
        return $cards
            ->groupBy(fn (Card $card) => $this->noteIdFor($card))
            ->map(function (Collection $group, string $noteId) use ($reviewCounts): array {
                /** @var Card $firstCard */
                $firstCard = $group->sortBy([
                    ['source_template_ord', 'asc'],
                    ['created_at', 'asc'],
                    ['id', 'asc'],
                ])->first();
                $createdAt = $group->min(fn (Card $card) => $card->created_at?->getTimestamp()) ?? 0;
                $updatedAt = $group->max(fn (Card $card) => $card->updated_at?->getTimestamp()) ?? 0;
                $queueSummary = [];
                $reviewCount = 0;

                foreach ($group as $card) {
                    $state = $card->study_status?->value ?? CardStudyStatus::New->value;
                    $queueSummary[$state] = ($queueSummary[$state] ?? 0) + 1;
                    $reviewCount += $reviewCounts[$card->id] ?? 0;
                }

                ksort($queueSummary);

                return [
                    'noteId' => $noteId,
                    'displayText' => $this->displayTextFor($firstCard),
                    'noteTypeName' => $firstCard->source_notetype_name,
                    'cardCount' => $group->count(),
                    'reviewCount' => $reviewCount,
                    'queueSummary' => $queueSummary,
                    'createdAt' => Carbon::createFromTimestamp($createdAt)->toJSON(),
                    'updatedAt' => Carbon::createFromTimestamp($updatedAt)->toJSON(),
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

    private function noteIdFor(Card $card): string
    {
        return $card->source_note_id === null ? (string) $card->id : (string) $card->source_note_id;
    }

    private function displayTextFor(Card $card): string
    {
        foreach (['cueText', 'expression', 'clozeText', 'text'] as $key) {
            $promptValue = $card->prompt_json[$key] ?? null;
            if (is_string($promptValue) && trim($promptValue) !== '') {
                return trim($promptValue);
            }

            $answerValue = $card->answer_json[$key] ?? null;
            if (is_string($answerValue) && trim($answerValue) !== '') {
                return trim($answerValue);
            }
        }

        return trim($card->front_text) !== '' ? $card->front_text : $card->id;
    }

    /**
     * @return list<string>
     */
    private function filterNoteTypes(
        int $userId,
        ?string $q,
        ?CardType $cardType,
        ?CardStudyStatus $queueState,
    ): array {
        return $this->browserCardQuery($userId, $q, null, $cardType, $queueState)
            ->distinct()
            ->orderBy('cards.source_notetype_name')
            ->pluck('cards.source_notetype_name')
            ->filter(fn (mixed $noteType): bool => is_string($noteType) && $noteType !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function filterCardTypes(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardStudyStatus $queueState,
    ): array {
        return $this->browserCardQuery($userId, $q, $noteType, null, $queueState)
            ->distinct()
            ->orderBy('cards.card_type')
            ->pluck('cards.card_type')
            ->map(fn (mixed $cardType): string => $this->cardTypeFacetValue($cardType))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function filterQueueStates(
        int $userId,
        ?string $q,
        ?string $noteType,
        ?CardType $cardType,
    ): array {
        return $this->browserCardQuery($userId, $q, $noteType, $cardType, null)
            ->distinct()
            ->orderBy('cards.study_status')
            ->pluck('cards.study_status')
            ->map(fn (mixed $queueState): string => $this->queueStateFacetValue($queueState))
            ->unique()
            ->values()
            ->all();
    }

    private function cardTypeFacetValue(mixed $cardType): string
    {
        if ($cardType instanceof CardType) {
            return $cardType->value;
        }

        if (is_string($cardType)) {
            return CardType::fromFilter($cardType)->value;
        }

        throw new InvalidArgumentException('Study browser card type facet must be a string or CardType.');
    }

    private function queueStateFacetValue(mixed $queueState): string
    {
        if ($queueState instanceof CardStudyStatus) {
            return $queueState->value;
        }

        if (is_string($queueState)) {
            return CardStudyStatus::fromFilter($queueState)->value;
        }

        throw new InvalidArgumentException('Study browser queue state facet must be a string or CardStudyStatus.');
    }

    private function encodeOffsetCursor(int $offset): string
    {
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
