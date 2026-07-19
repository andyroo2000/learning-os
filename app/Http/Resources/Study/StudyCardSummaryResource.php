<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Support\DateTime\ConvoLabTimestamp;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use UnexpectedValueException;

class StudyCardSummaryResource extends JsonResource
{
    private const ANSWER_AUDIO_SOURCE_MISSING = 'missing';

    private const CONVOLAB_DEFAULT_DECK_NAME = '日本語';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->clientId(),
            // The root uses ConvoLab's public note UUID; source.noteId retains the original Anki ID.
            'noteId' => $this->noteIdString(),
            'cardType' => $this->card_type?->value ?? CardType::Recognition->value,
            'prompt' => $this->prompt_json ?? ['type' => 'text', 'text' => $this->front_text],
            'answer' => $this->answer_json ?? ['type' => 'text', 'text' => $this->back_text],
            'state' => [
                'dueAt' => ConvoLabTimestamp::serialize($this->due_at),
                'introducedAt' => ConvoLabTimestamp::serialize($this->introduced_at),
                'failedAt' => ConvoLabTimestamp::serialize($this->failed_at),
                'queueState' => $this->study_status?->value ?? CardStudyStatus::New->value,
                // ConvoLab clients interpret scheduler internals; expose the stored state verbatim.
                'scheduler' => $this->scheduler_state,
                'source' => [
                    'noteId' => $this->source_note_id === null ? null : (string) $this->source_note_id,
                    'noteGuid' => $this->convolab_note_source_guid,
                    'cardId' => $this->source_card_id === null ? null : (string) $this->source_card_id,
                    'deckId' => $this->source_deck_id === null ? null : (string) $this->source_deck_id,
                    'deckName' => $this->sourceDeckName(),
                    'notetypeId' => $this->convolab_note_source_notetype_id === null
                        ? null
                        : (string) $this->convolab_note_source_notetype_id,
                    'notetypeName' => $this->source_notetype_name,
                    'templateOrd' => $this->source_template_ord,
                    'templateName' => $this->source_template_name,
                    'queue' => $this->source_queue,
                    'type' => $this->source_card_type,
                    'due' => $this->source_due,
                    'ivl' => $this->source_interval,
                    'factor' => $this->source_factor,
                    'reps' => $this->source_reps,
                    'lapses' => $this->source_lapses,
                    'left' => $this->source_left,
                    'odue' => $this->source_original_due,
                    'odid' => $this->source_original_deck_id === null ? null : (string) $this->source_original_deck_id,
                ],
                'rawFsrs' => $this->source_fsrs_json,
            ],
            'variantGroupId' => $this->variant_group_id,
            'variantSentenceId' => $this->variant_sentence_id,
            'variantKind' => $this->stringAttributeValue('variant_kind'),
            'variantStage' => $this->variant_stage,
            'variantStatus' => $this->stringAttributeValue('variant_status'),
            'variantUnlockedAt' => ConvoLabTimestamp::serialize($this->variant_unlocked_at),
            'answerAudioSource' => $this->answer_audio_source ?? self::ANSWER_AUDIO_SOURCE_MISSING,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }

    private function noteIdString(): ?string
    {
        $convoLabNoteId = $this->resource->getAttribute('convolab_note_id');

        if (is_string($convoLabNoteId) && $convoLabNoteId !== '') {
            return $convoLabNoteId;
        }

        // Native manual cards retain the established null noteId contract outside Browser grouping.
        return $this->source_note_id === null ? null : (string) $this->source_note_id;
    }

    private function sourceDeckName(): ?string
    {
        if (is_string($this->source_deck_name)) {
            return $this->source_deck_name;
        }

        return $this->resource->getAttribute('convolab_id') === null
            ? null
            : self::CONVOLAB_DEFAULT_DECK_NAME;
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
