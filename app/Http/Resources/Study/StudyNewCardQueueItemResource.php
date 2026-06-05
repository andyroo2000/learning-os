<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyNewCardQueueItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'noteId' => $this->source_note_id === null ? $this->id : (string) $this->source_note_id,
            'cardType' => $this->card_type?->value ?? CardType::Recognition->value,
            'displayText' => $this->displayText(),
            'meaning' => $this->meaning(),
            'queuePosition' => $this->new_queue_position,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }

    private function displayText(): string
    {
        return $this->stringField($this->prompt_json, ['cueText', 'clozeDisplayText', 'clozeText'])
            ?? $this->stringField($this->answer_json, ['expression', 'restoredText', 'meaning'])
            ?? $this->front_text
            ?? 'Untitled card';
    }

    private function meaning(): ?string
    {
        return $this->stringField($this->answer_json, ['meaning', 'sentenceEn'])
            ?? $this->stringField($this->prompt_json, ['cueMeaning'])
            ?? $this->back_text;
    }

    /**
     * @param  list<string>  $keys
     */
    private function stringField(mixed $payload, array $keys): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
