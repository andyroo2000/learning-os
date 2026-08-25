<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Support\StudyCardListText;
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
            'displayText' => StudyCardListText::displayText($this->resource),
            'meaning' => StudyCardListText::meaning($this->resource),
            'variantKind' => $this->stringField($this->resource->variant_kind),
        ];
    }

    private function stringField(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
