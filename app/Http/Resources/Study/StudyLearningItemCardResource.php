<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyLearningItemCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->clientId(),
            'syncId' => (string) $this->resource->getKey(),
            'noteId' => $this->resource->clientNoteId(),
            'cardType' => $this->resource->card_type?->value ?? CardType::Recognition->value,
            'displayText' => $this->displayText(),
            'meaning' => $this->meaning(),
            'variantKind' => $this->stringField($this->resource->variant_kind),
        ];
    }

    private function displayText(): string
    {
        return $this->payloadField($this->resource->prompt_json, [
            'clozeDisplayText',
            'cueText',
        ])
            ?? $this->payloadField($this->resource->answer_json, [
                'expressionReading',
                'expression',
            ])
            ?? $this->payloadField($this->resource->prompt_json, ['clozeText'])
            ?? $this->stringField($this->resource->front_text)
            ?? (string) $this->resource->getKey();
    }

    private function meaning(): ?string
    {
        return $this->payloadField($this->resource->answer_json, ['meaning'])
            ?? $this->payloadField($this->resource->prompt_json, ['cueMeaning'])
            ?? $this->payloadField($this->resource->answer_json, ['sentenceEn'])
            ?? $this->stringField($this->resource->back_text);
    }

    /**
     * @param  list<string>  $keys
     */
    private function payloadField(mixed $payload, array $keys): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->stringField($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function stringField(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
