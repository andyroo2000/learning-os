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
            'status' => $this->attributeValue('status'),
            'creationKind' => $this->attributeValue('creation_kind'),
            'cardType' => $this->attributeValue('card_type'),
            'prompt' => $this->prompt_json,
            'answer' => $this->answer_json,
            'imagePlacement' => $this->attributeValue('image_placement'),
            'imagePrompt' => $this->image_prompt,
            'previewAudio' => $this->preview_audio_json,
            'previewAudioRole' => $this->attributeValue('preview_audio_role'),
            'previewImage' => $this->preview_image_json,
            'errorMessage' => $this->error_message,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }

    private function attributeValue(string $key): ?string
    {
        // Preserve Eloquent enum casts while serializing the public scalar value.
        $value = $this->resource->getAttribute($key);

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new UnexpectedValueException("Study card draft attribute [{$key}] must serialize to a scalar value.");
        }

        return (string) $value;
    }
}
