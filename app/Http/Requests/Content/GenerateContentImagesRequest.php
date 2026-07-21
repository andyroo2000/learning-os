<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Data\GenerateContentImagesData;

final class GenerateContentImagesRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        foreach (['episodeId', 'dialogueId'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $this->merge([$key => strtolower(trim($value))]);
            }
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'episodeId' => ['required', 'uuid'],
            'dialogueId' => ['required', 'uuid'],
            'imageCount' => ['sometimes', 'required', 'integer', 'between:1,'.GenerateContentImagesData::MAX_IMAGE_COUNT],
        ];
    }

    public function generationData(): GenerateContentImagesData
    {
        $validated = $this->validated();

        return GenerateContentImagesData::fromInput([
            'episodeId' => $validated['episodeId'],
            'dialogueId' => $validated['dialogueId'],
            'imageCount' => array_key_exists('imageCount', $validated)
                ? (int) $validated['imageCount']
                : GenerateContentImagesData::DEFAULT_IMAGE_COUNT,
        ]);
    }
}
