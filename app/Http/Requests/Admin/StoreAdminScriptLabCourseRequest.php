<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

final class StoreAdminScriptLabCourseRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        foreach (['title', 'sourceText'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if ($this->has('episodeId') && is_string($this->input('episodeId'))) {
            $this->merge(['episodeId' => strtolower(trim($this->input('episodeId')))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => ['required', 'string', 'max:255'],
            'sourceText' => ['required', 'string'],
            'episodeId' => ['sometimes', 'nullable', 'uuid'],
            'targetLanguage' => ['sometimes', 'string', Rule::in(['ja'])],
            'nativeLanguage' => ['sometimes', 'string', Rule::in(['en'])],
            'jlptLevel' => ['sometimes', 'nullable', 'string', Rule::in(['N5', 'N4', 'N3', 'N2', 'N1'])],
            'maxDurationMinutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'speaker1Gender' => ['sometimes', 'string', Rule::in(['male', 'female'])],
            'speaker2Gender' => ['sometimes', 'string', Rule::in(['male', 'female'])],
        ];
    }
}
