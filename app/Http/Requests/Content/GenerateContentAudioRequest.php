<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Data\GenerateContentAudioData;
use Illuminate\Validation\Rule;

final class GenerateContentAudioRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $normalized = [
            'episodeId' => $this->normalizeUuid($this->input('episodeId')),
            'dialogueId' => $this->normalizeUuid($this->input('dialogueId')),
        ];
        if ($this->exists('speed')) {
            $normalized['speed'] = is_string($this->input('speed'))
                ? trim($this->input('speed'))
                : $this->input('speed');
        }
        $this->merge($normalized);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'episodeId' => ['required', 'uuid'],
            'dialogueId' => ['required', 'uuid'],
            'speed' => ['sometimes', 'required', 'string', Rule::in(['very-slow', 'slow', 'medium', 'normal'])],
            'pauseMode' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function generationData(): GenerateContentAudioData
    {
        $validated = $this->validated();

        return GenerateContentAudioData::fromInput([
            'episodeId' => $validated['episodeId'],
            'dialogueId' => $validated['dialogueId'],
            'mode' => GenerateContentAudioData::MODE_SINGLE,
            'speed' => $validated['speed'] ?? 'normal',
            'pauseMode' => $validated['pauseMode'] ?? false,
        ]);
    }

    private function normalizeUuid(mixed $value): mixed
    {
        return is_string($value) ? strtolower(trim($value)) : $value;
    }
}
