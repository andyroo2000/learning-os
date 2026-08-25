<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Support\StudyCardListText;
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
            'displayText' => StudyCardListText::displayText($this->resource),
            'meaning' => StudyCardListText::meaning($this->resource),
            'queuePosition' => $this->new_queue_position,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }
}
