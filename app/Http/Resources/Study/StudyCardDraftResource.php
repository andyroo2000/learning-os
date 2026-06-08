<?php

namespace App\Http\Resources\Study;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use UnexpectedValueException;

class StudyCardDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->stringAttributeValue('status'),
            'creationKind' => $this->stringAttributeValue('creation_kind'),
            'cardType' => $this->stringAttributeValue('card_type'),
            'prompt' => $this->prompt_json,
            'answer' => $this->answer_json,
            'imagePlacement' => $this->stringAttributeValue('image_placement'),
            'imagePrompt' => $this->image_prompt,
            'previewAudio' => $this->preview_audio_json,
            'previewAudioRole' => $this->stringAttributeValue('preview_audio_role'),
            'previewImage' => $this->preview_image_json,
            'variantGroupId' => $this->variant_group_id,
            'variantSentenceId' => $this->variant_sentence_id,
            'variantKind' => $this->stringAttributeValue('variant_kind'),
            'variantStage' => $this->variant_stage,
            'variantStatus' => $this->stringAttributeValue('variant_status'),
            'variantUnlockedAt' => $this->variant_unlocked_at?->toJSON(),
            'errorMessage' => $this->error_message,
            'committedCardId' => $this->committed_card_id,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }

    private function stringAttributeValue(string $key): ?string
    {
        // These public fields have a string wire contract; BackedEnum casts must stay string-backed.
        $value = $this->resource->getAttribute($key);

        if ($value instanceof BackedEnum) {
            if (is_int($value->value)) {
                throw new UnexpectedValueException(
                    "Study card draft attribute [{$key}] must serialize to a string or null. Integer-backed enums are not supported."
                );
            }

            $value = $value->value;
        }

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException("Study card draft attribute [{$key}] must serialize to a string or null.");
    }
}
