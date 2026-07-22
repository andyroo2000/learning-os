<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\TestAdminPronunciationData;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Validation\Rule;

final class TestAdminPronunciationRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];
        foreach (['text', 'format', 'voiceId'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }
        foreach (['format', 'voiceId'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = strtolower($normalized[$field]);
            }
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
            'format' => ['required', 'string', Rule::in(TestAdminPronunciationData::FORMATS)],
            'voiceId' => ['required', 'string', 'regex:/^fishaudio:[a-f0-9]{32}$/'],
            'speed' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
        ];
    }

    public function pronunciationData(): TestAdminPronunciationData
    {
        return TestAdminPronunciationData::fromInput(
            $this->safe()->only(['text', 'format', 'voiceId', 'speed']),
        );
    }
}
