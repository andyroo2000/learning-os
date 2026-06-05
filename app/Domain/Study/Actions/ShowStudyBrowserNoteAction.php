<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyBrowserCardDisplay;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

class ShowStudyBrowserNoteAction
{
    /**
     * @return array<string, mixed>|null
     */
    public function handle(int $userId, string $noteId): ?array
    {
        $cards = $this->cardsForNote($userId, $noteId);

        if ($cards->isEmpty()) {
            return null;
        }

        /** @var Card $firstCard */
        $firstCard = $cards->first();

        return [
            'noteId' => $noteId,
            'displayText' => StudyBrowserCardDisplay::displayTextFor($firstCard),
            'noteTypeName' => $firstCard->source_notetype_name,
            'sourceKind' => is_string($firstCard->source_kind) && $firstCard->source_kind !== ''
                ? $firstCard->source_kind
                : 'native',
            'updatedAt' => $cards->max(fn (Card $card) => $card->updated_at)?->toJSON(),
            'rawFields' => $this->fieldsForCards($cards),
            'canonicalFields' => $this->canonicalFieldsForCards($cards),
            'cards' => $cards,
            'cardStats' => $cards
                ->map(fn (Card $card): array => [
                    'cardId' => $card->id,
                    'reviewCount' => (int) ($card->getAttribute('review_events_count') ?? 0),
                    'lastReviewedAt' => $this->lastReviewedAt($card->getAttribute('review_events_max_reviewed_at')),
                ])
                ->values()
                ->all(),
            'selectedCardId' => $firstCard->id,
        ];
    }

    /**
     * @return EloquentCollection<int, Card>
     */
    private function cardsForNote(int $userId, string $noteId): EloquentCollection
    {
        $query = Card::query()
            ->ownedByActiveDeck($userId)
            ->withCount('reviewEvents')
            ->withMax('reviewEvents', 'reviewed_at')
            ->orderBy('cards.source_template_ord')
            ->orderBy('cards.created_at')
            ->orderBy('cards.id');

        if (ctype_digit($noteId)) {
            $query->where('cards.source_note_id', (int) $noteId);
        } else {
            $query->whereNull('cards.source_note_id')->whereKey($noteId);
        }

        return $query->get();
    }

    private function lastReviewedAt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->toJSON();
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return list<array{name: string, value: string|null, textValue: string|null, audio: null, image: null}>
     */
    private function fieldsForCards(EloquentCollection $cards): array
    {
        $fields = [];

        foreach ($cards as $card) {
            $this->appendPayloadFields($fields, 'prompt', $card->prompt_json);
            $this->appendPayloadFields($fields, 'answer', $card->answer_json);
        }

        if ($fields === []) {
            /** @var Card $firstCard */
            $firstCard = $cards->first();
            $fields[] = $this->field('frontText', $firstCard->front_text);
            $fields[] = $this->field('backText', $firstCard->back_text);
        }

        return $fields;
    }

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return list<array{name: string, value: string|null, textValue: string|null, audio: null, image: null}>
     */
    private function canonicalFieldsForCards(EloquentCollection $cards): array
    {
        /** @var Card $firstCard */
        $firstCard = $cards->first();

        return [
            $this->field('displayText', StudyBrowserCardDisplay::displayTextFor($firstCard)),
            $this->field('noteTypeName', $firstCard->source_notetype_name),
        ];
    }

    /**
     * @param  list<array{name: string, value: string|null, textValue: string|null, audio: null, image: null}>  $fields
     */
    private function appendPayloadFields(array &$fields, string $prefix, mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $fields[] = $this->field("{$prefix}.{$key}", $value);
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
