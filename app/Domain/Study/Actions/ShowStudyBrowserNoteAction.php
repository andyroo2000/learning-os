<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\StudyBrowserNoteDetailResult;
use App\Domain\Study\Support\StudyBrowserCardAggregate;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use App\Domain\Study\Support\StudyFieldMediaReferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ShowStudyBrowserNoteAction
{
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
            noteId: StudyBrowserCardAggregate::noteIdFor($firstCard),
            displayText: $displayText,
            noteTypeName: $firstCard->source_notetype_name,
            sourceKind: StudyBrowserCardAggregate::sourceKindFor($firstCard),
            reviewCount: StudyBrowserCardAggregate::reviewCount($cards),
            lastReviewedAt: StudyBrowserCardAggregate::lastReviewedAt($cards),
            updatedAt: StudyBrowserCardAggregate::noteUpdatedAt($cards),
            rawFields: $this->fieldsForCards($cards),
            canonicalFields: $this->canonicalFieldsForCards($cards, $displayText),
            cards: $cards,
            cardStats: StudyBrowserCardAggregate::cardStats($cards),
            // The first card mirrors the deterministic card ordering used by the legacy browser detail.
            selectedCardId: $firstCard->clientId(),
        );
    }

    /**
     * @return EloquentCollection<int, Card>
     */
    private function cardsForNote(int $userId, string $noteId): EloquentCollection
    {
        $query = Card::query()
            ->ownedByActiveDeck($userId);

        if (Str::isUuid($noteId)) {
            $query->where('cards.convolab_note_id', strtolower($noteId));

            return $this->cardsWithReviewStats($query);
        }

        $sourceNoteId = $this->sourceNoteIdValue($noteId);

        if ($sourceNoteId !== null) {
            $query
                ->whereNull('cards.convolab_note_id')
                ->where('cards.source_note_id', $sourceNoteId);

            return $this->cardsWithReviewStats($query);
        }

        if (! Str::isUlid($noteId)) {
            return new EloquentCollection;
        }

        $cardIdCandidates = $this->cardIdCandidates($noteId);
        $query
            ->whereNull('cards.convolab_note_id')
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
                'cards.convolab_id',
                'cards.convolab_note_id',
                'cards.convolab_note_created_at',
                'cards.convolab_note_updated_at',
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
            // NULL marks cards with no reviews; groupLastReviewedAt filters those before maxing the note.
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

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return list<array{name: string, value: string|null, textValue: string|null, audio: array<string, mixed>|null, image: array<string, mixed>|null}>
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
     * @return list<array{name: string, value: string|null, textValue: string|null, audio: array<string, mixed>|null, image: array<string, mixed>|null}>
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
     * @param  array<string, array{name: string, value: string|null, textValue: string|null, audio: array<string, mixed>|null, image: array<string, mixed>|null}>  $fieldsByName
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
     * @return array{name: string, value: string|null, textValue: string|null, audio: array<string, mixed>|null, image: array<string, mixed>|null}
     */
    private function field(string $name, mixed $value): array
    {
        $textValue = $this->fieldTextValue($value);
        $media = StudyFieldMediaReferences::fromValue($value);

        return [
            'name' => $name,
            'value' => $textValue,
            'textValue' => $textValue,
            'audio' => $media['audio'],
            'image' => $media['image'],
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
