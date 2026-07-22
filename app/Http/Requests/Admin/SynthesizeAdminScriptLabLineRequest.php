<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\SynthesizeAdminScriptLabLineData;
use App\Support\Audio\FishAudioSpeechGenerator;

final class SynthesizeAdminScriptLabLineRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];
        foreach (['text', 'voiceId'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }
        if (isset($normalized['voiceId'])) {
            $normalized['voiceId'] = strtolower($normalized['voiceId']);
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'text' => ['required', 'string', 'max:'.FishAudioSpeechGenerator::MAX_TEXT_LENGTH],
            'voiceId' => ['required', 'string', 'regex:/^fishaudio:[a-f0-9]{32}$/'],
            'speed' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
        ];
    }

    public function synthesisData(): SynthesizeAdminScriptLabLineData
    {
        return SynthesizeAdminScriptLabLineData::fromInput(
            $this->safe()->only(['text', 'voiceId', 'speed']),
        );
    }
}
