<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyCardSummaryResource extends JsonResource
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
            'prompt' => $this->prompt_json ?? ['type' => 'text', 'text' => $this->front_text],
            'answer' => $this->answer_json ?? ['type' => 'text', 'text' => $this->back_text],
            'state' => [
                'dueAt' => $this->due_at?->toJSON(),
                'introducedAt' => $this->introduced_at?->toJSON(),
                'failedAt' => $this->failed_at?->toJSON(),
                'queueState' => $this->study_status?->value ?? CardStudyStatus::New->value,
                'scheduler' => $this->scheduler_state,
                'source' => [
                    'noteId' => $this->source_note_id === null ? null : (string) $this->source_note_id,
                    'noteGuid' => null,
                    'cardId' => $this->source_card_id === null ? null : (string) $this->source_card_id,
                    'deckId' => $this->source_deck_id === null ? null : (string) $this->source_deck_id,
                    'deckName' => null,
                    'notetypeId' => null,
                    'notetypeName' => $this->source_notetype_name,
                    'templateOrd' => $this->source_template_ord,
                    'templateName' => null,
                    'queue' => null,
                    'type' => null,
                    'due' => null,
                    'ivl' => null,
                    'factor' => null,
                    'reps' => null,
                    'lapses' => null,
                    'left' => null,
                    'odue' => null,
                    'odid' => null,
                ],
                'rawFsrs' => null,
            ],
            'answerAudioSource' => 'missing',
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }
}
