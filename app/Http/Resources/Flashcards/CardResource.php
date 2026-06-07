<?php

namespace App\Http\Resources\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deck_id' => $this->deck_id,
            'course_id' => $this->deckCourseId(),
            'import_job_id' => $this->import_job_id,
            'source_kind' => $this->source_kind,
            'source_card_id' => $this->source_card_id,
            'source_note_id' => $this->source_note_id,
            'source_deck_id' => $this->source_deck_id,
            'source_notetype_name' => $this->source_notetype_name,
            'source_template_ord' => $this->source_template_ord,
            'front_text' => $this->front_text,
            'back_text' => $this->back_text,
            'card_type' => $this->card_type?->value ?? CardType::Recognition->value,
            'prompt_json' => $this->prompt_json,
            'answer_json' => $this->answer_json,
            'search_text' => $this->search_text ?? '',
            'study_status' => $this->study_status?->value ?? CardStudyStatus::New->value,
            'new_queue_position' => $this->new_queue_position,
            'scheduler_state' => $this->scheduler_state,
            'variant_group_id' => $this->variant_group_id,
            'variant_sentence_id' => $this->variant_sentence_id,
            'variant_kind' => $this->variant_kind?->value,
            'variant_stage' => $this->variant_stage,
            'variant_status' => $this->variant_status?->value,
            'variant_unlocked_at' => $this->variant_unlocked_at?->toJSON(),
            'due_at' => $this->due_at?->toJSON(),
            'introduced_at' => $this->introduced_at?->toJSON(),
            'failed_at' => $this->failed_at?->toJSON(),
            'last_reviewed_at' => $this->last_reviewed_at?->toJSON(),
            // Cross-domain resource by design while cards own the response envelope.
            'media_assets' => $this->whenLoaded('mediaAssets', fn () => MediaAssetResource::collection($this->mediaAssets)),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
