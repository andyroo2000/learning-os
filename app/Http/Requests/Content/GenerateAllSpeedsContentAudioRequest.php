<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Data\GenerateContentAudioData;

final class GenerateAllSpeedsContentAudioRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge([
            'episodeId' => $this->normalizeUuid($this->input('episodeId')),
            'dialogueId' => $this->normalizeUuid($this->input('dialogueId')),
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'episodeId' => ['required', 'uuid'],
            'dialogueId' => ['required', 'uuid'],
        ];
    }

    public function generationData(): GenerateContentAudioData
    {
        $validated = $this->validated();

        return GenerateContentAudioData::fromInput([
            'episodeId' => $validated['episodeId'],
            'dialogueId' => $validated['dialogueId'],
            'mode' => GenerateContentAudioData::MODE_ALL_SPEEDS,
        ]);
    }

    private function normalizeUuid(mixed $value): mixed
    {
        return is_string($value) ? strtolower(trim($value)) : $value;
    }
}
