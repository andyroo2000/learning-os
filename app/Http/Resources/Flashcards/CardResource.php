<?php

namespace App\Http\Resources\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
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
            'front_text' => $this->front_text,
            'back_text' => $this->back_text,
            'study_status' => $this->study_status?->value ?? CardStudyStatus::New->value,
            'new_queue_position' => $this->new_queue_position,
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
