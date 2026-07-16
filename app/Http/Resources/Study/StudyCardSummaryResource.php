<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use UnexpectedValueException;

class StudyCardSummaryResource extends JsonResource
{
    private const ANSWER_AUDIO_SOURCE_MISSING = 'missing';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->clientId(),
            // ConvoLab exposes noteId at both the root and state.source; keep both values aligned.
            'noteId' => $this->noteIdString(),
            'cardType' => $this->card_type?->value ?? CardType::Recognition->value,
            'prompt' => $this->prompt_json ?? ['type' => 'text', 'text' => $this->front_text],
            'answer' => $this->answer_json ?? ['type' => 'text', 'text' => $this->back_text],
            'state' => [
                'dueAt' => $this->due_at?->toJSON(),
                'introducedAt' => $this->introduced_at?->toJSON(),
                'failedAt' => $this->failed_at?->toJSON(),
                'queueState' => $this->study_status?->value ?? CardStudyStatus::New->value,
                // ConvoLab clients interpret scheduler internals; expose the stored state verbatim.
                'scheduler' => $this->scheduler_state,
                'source' => [
                    'noteId' => $this->noteIdString(),
                    // Anki-only source fields remain present for ConvoLab compatibility.
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
            'variantGroupId' => $this->variant_group_id,
            'variantSentenceId' => $this->variant_sentence_id,
            'variantKind' => $this->stringAttributeValue('variant_kind'),
            'variantStage' => $this->variant_stage,
            'variantStatus' => $this->stringAttributeValue('variant_status'),
            'variantUnlockedAt' => $this->variant_unlocked_at?->toJSON(),
            // Laravel cards do not track generated/imported audio roles yet; expose the safe ConvoLab sentinel.
            'answerAudioSource' => self::ANSWER_AUDIO_SOURCE_MISSING,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }

    private function noteIdString(): ?string
    {
        $convoLabNoteId = $this->resource->getAttribute('convolab_note_id');

        if (is_string($convoLabNoteId) && $convoLabNoteId !== '') {
            return $convoLabNoteId;
        }

        return $this->source_note_id === null ? null : (string) $this->source_note_id;
    }

    private function stringAttributeValue(string $key): ?string
    {
        // These public fields have a string wire contract; BackedEnum casts must stay string-backed.
        $value = $this->resource->getAttribute($key);

        if ($value instanceof BackedEnum) {
            if (is_int($value->value)) {
                throw new UnexpectedValueException(
                    "Study card attribute [{$key}] must serialize to a string or null. Integer-backed enums are not supported."
                );
            }

            $value = $value->value;
        }

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException("Study card attribute [{$key}] must serialize to a string or null.");
    }
}
