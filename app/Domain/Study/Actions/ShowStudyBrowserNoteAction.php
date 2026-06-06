<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\StudyBrowserNoteDetailResult;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ShowStudyBrowserNoteAction
{
    private const SOURCE_KIND_NATIVE = 'native';

    public function handle(int $userId, string $noteId): ?StudyBrowserNoteDetailResult
    {
        $noteId = trim($noteId);

        if ($noteId === '') {
            return null;
        }

        $cards = $this->cardsForNote($userId, $noteId);

        if ($cards->isEmpty()) {
            return null;
        }

        /** @var Card $firstCard */
        $firstCard = $cards->first();
        $displayText = StudyBrowserCardDisplay::displayTextFor($firstCard);

        return new StudyBrowserNoteDetailResult(
            noteId: $this->noteIdForResult($firstCard),
            displayText: $displayText,
            noteTypeName: $firstCard->source_notetype_name,
            sourceKind: is_string($firstCard->source_kind) && $firstCard->source_kind !== ''
                ? $firstCard->source_kind
                : self::SOURCE_KIND_NATIVE,
            updatedAt: $cards->map(fn (Card $card) => $card->updated_at)->filter()->max()?->toJSON(),
            rawFields: $this->fieldsForCards($cards),
            canonicalFields: $this->canonicalFieldsForCards($cards, $displayText),
            cards: $cards,
            cardStats: $cards
                ->map(fn (Card $card): array => [
                    'cardId' => $card->id,
                    'reviewCount' => (int) ($card->getAttribute('review_events_count') ?? 0),
                    'lastReviewedAt' => $this->lastReviewedAt($card->getAttribute('review_events_max_reviewed_at')),
                ])
                ->values()
                ->all(),
            // The first card mirrors the deterministic card ordering used by the legacy browser detail.
            selectedCardId: $firstCard->id,
        );
    }

    /**
     * @return EloquentCollection<int, Card>
     */
    private function cardsForNote(int $userId, string $noteId): EloquentCollection
    {
        $query = Card::query()
            ->ownedByActiveDeck($userId);

        $sourceNoteId = $this->sourceNoteIdValue($noteId);

        if ($sourceNoteId !== null) {
            $query->where('cards.source_note_id', $sourceNoteId);

            return $this->cardsWithReviewStats($query);
        }

        if (! Str::isUlid($noteId)) {
            return new EloquentCollection;
        }

        $cardIdCandidates = $this->cardIdCandidates($noteId);
        $query
            ->whereNull('cards.source_note_id')
            ->whereIn('cards.id', $cardIdCandidates);

        return $this->cardsForPreferredCardId($this->cardsWithReviewStats($query), $cardIdCandidates);
    }

    /**
     * @param  Builder<Card>  $query
     * @return EloquentCollection<int, Card>
     */
    private function cardsWithReviewStats(Builder $query): EloquentCollection
    {
        // Mirror the outer filters so the review aggregate scans only stats for this user's visible note cards.
        $matchingCardIds = (clone $query)
            ->select('cards.id')
            ->toBase();

        return $query
            ->leftJoinSub(
                $this->reviewStatsSubquery($matchingCardIds),
                'review_event_stats',
                fn (JoinClause $join) => $join->on('review_event_stats.card_id', '=', 'cards.id'),
            )
            ->select([
                'cards.id',
                'cards.front_text',
                'cards.back_text',
                'cards.card_type',
                'cards.prompt_json',
                'cards.answer_json',
                'cards.study_status',
                'cards.scheduler_state',
                'cards.due_at',
                'cards.introduced_at',
                'cards.failed_at',
                'cards.source_kind',
                'cards.source_card_id',
                'cards.source_note_id',
                'cards.source_deck_id',
                'cards.source_notetype_name',
                'cards.source_template_ord',
                'cards.created_at',
                'cards.updated_at',
            ])
            ->selectRaw('coalesce(review_event_stats.review_events_count, 0) as review_events_count')
            ->addSelect('review_event_stats.review_events_max_reviewed_at')
            ->orderBy('cards.source_template_ord')
            ->orderBy('cards.created_at')
            ->orderBy('cards.id')
            ->get();
    }

    private function reviewStatsSubquery(QueryBuilder $matchingCardIds): QueryBuilder
    {
        return DB::table('card_review_events')
            ->select('card_id')
            ->selectRaw('count(*) as review_events_count')
            ->selectRaw('max(reviewed_at) as review_events_max_reviewed_at')
            ->whereIn('card_id', $matchingCardIds)
            ->groupBy('card_id');
    }

    private function lastReviewedAt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        // Aggregate timestamp hydration differs across drivers, so normalize raw SQL strings too.
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->toJSON();
        }

        return null;
    }

    private function sourceNoteIdValue(string $noteId): ?int
    {
        if (! ctype_digit($noteId)) {
            return null;
        }

        $normalized = ltrim($noteId, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;

        if (strlen($normalized) > strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * Build exact candidates instead of wrapping cards.id in LOWER(), preserving primary-key lookups on Postgres.
     *
     * @return list<string>
     */
    private function cardIdCandidates(string $noteId): array
    {
        // HasUlids stores server-generated IDs uppercase, while client-provided IDs are canonicalized lowercase.
        return array_values(array_unique([
            $noteId,
            strtolower($noteId),
            strtoupper($noteId),
        ]));
    }

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @param  list<string>  $cardIdCandidates
     * @return EloquentCollection<int, Card>
     */
    private function cardsForPreferredCardId(EloquentCollection $cards, array $cardIdCandidates): EloquentCollection
    {
        if ($cards->isEmpty()) {
            return $cards;
        }

        foreach ($cardIdCandidates as $candidate) {
            $matchingCards = $cards
                ->filter(fn (Card $card): bool => $card->id === $candidate)
                ->values();

            if ($matchingCards->isNotEmpty()) {
                return $matchingCards;
            }
        }

        throw new LogicException('Study browser card ID candidate query returned a non-candidate card.');
    }

    private function noteIdForResult(Card $card): string
    {
        return $card->source_note_id === null ? (string) $card->id : (string) $card->source_note_id;
    }

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return list<array{name: string, value: string|null, textValue: string|null, audio: null, image: null}>
     */
    private function fieldsForCards(EloquentCollection $cards): array
    {
        $fieldsByName = [];

        foreach ($cards as $card) {
            $this->appendPayloadFields($fieldsByName, 'prompt', $card->prompt_json);
            $this->appendPayloadFields($fieldsByName, 'answer', $card->answer_json);
        }

        if ($fieldsByName === []) {
            /** @var Card $firstCard */
            $firstCard = $cards->first();
            // The fallback path is card-scoped for native unsourced rows that have no structured payload fields.
            $fieldsByName['frontText'] = $this->field('frontText', $firstCard->front_text);
            $fieldsByName['backText'] = $this->field('backText', $firstCard->back_text);
        }

        return array_values($fieldsByName);
    }

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return list<array{name: string, value: string|null, textValue: string|null, audio: null, image: null}>
     */
    private function canonicalFieldsForCards(EloquentCollection $cards, string $displayText): array
    {
        /** @var Card $firstCard */
        $firstCard = $cards->first();

        return [
            $this->field('displayText', $displayText),
            $this->field('noteTypeName', $firstCard->source_notetype_name),
        ];
    }

    /**
     * @param  array<string, array{name: string, value: string|null, textValue: string|null, audio: null, image: null}>  $fieldsByName
     */
    private function appendPayloadFields(array &$fieldsByName, string $prefix, mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $name = "{$prefix}.{$key}";
            $field = $this->field($name, $value);

            // Note-level fields should be unique; keep the first meaningful value across card templates.
            if (! array_key_exists($name, $fieldsByName) || ($fieldsByName[$name]['value'] === null && $field['value'] !== null)) {
                $fieldsByName[$name] = $field;
            }
        }
    }

    /**
     * @return array{name: string, value: string|null, textValue: string|null, audio: null, image: null}
     */
    private function field(string $name, mixed $value): array
    {
        $textValue = $this->fieldTextValue($value);

        return [
            'name' => $name,
            // Keep both ConvoLab-compatible keys aligned until richer media field parsing exists.
            'value' => $textValue,
            'textValue' => $textValue,
            'audio' => null,
            'image' => null,
        ];
    }

    private function fieldTextValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? null : $text;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }
}
