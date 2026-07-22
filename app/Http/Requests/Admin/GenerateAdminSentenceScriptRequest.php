<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\GenerateAdminSentenceScriptData;

final class GenerateAdminSentenceScriptRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];
        foreach (['sentence', 'translation', 'targetLanguage', 'nativeLanguage', 'jlptLevel', 'l1VoiceId', 'l2VoiceId'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }
        foreach (['targetLanguage', 'nativeLanguage', 'l1VoiceId', 'l2VoiceId'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = strtolower($normalized[$field]);
            }
        }
        if (is_string($this->input('promptOverride'))) {
            $normalized['promptOverride'] = trim($this->input('promptOverride'));
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sentence' => ['required', 'string', 'max:'.GenerateAdminSentenceScriptData::MAX_SENTENCE_LENGTH],
            'translation' => ['sometimes', 'nullable', 'string', 'max:'.GenerateAdminSentenceScriptData::MAX_SENTENCE_LENGTH],
            'targetLanguage' => ['sometimes', 'string', 'max:16', 'regex:/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i'],
            'nativeLanguage' => ['sometimes', 'string', 'max:16', 'regex:/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i'],
            'jlptLevel' => ['sometimes', 'nullable', 'string', 'max:32'],
            'l1VoiceId' => ['sometimes', 'string', 'regex:/^fishaudio:[a-f0-9]{32}$/'],
            'l2VoiceId' => ['sometimes', 'string', 'regex:/^fishaudio:[a-f0-9]{32}$/'],
            'promptOverride' => ['sometimes', 'nullable', 'string', 'max:'.GenerateAdminSentenceScriptData::MAX_PROMPT_LENGTH],
        ];
    }

    public function generationData(): GenerateAdminSentenceScriptData
    {
        return GenerateAdminSentenceScriptData::fromInput($this->safe()->only([
            'sentence',
            'translation',
            'targetLanguage',
            'nativeLanguage',
            'jlptLevel',
            'l1VoiceId',
            'l2VoiceId',
            'promptOverride',
        ]));
    }
}
