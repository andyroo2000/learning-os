<?php

namespace App\Http\Requests\Content;

use Illuminate\Validation\Rule;

class StoreContentCourseRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('sourceText')) && trim($this->input('sourceText')) !== '') {
            $input = $this->all();
            unset($input['episodeIds']);
            $this->replace($input);

            return;
        }

        if (! is_array($this->input('episodeIds'))) {
            return;
        }

        $this->merge([
            'episodeIds' => array_map(
                static fn (mixed $id): mixed => is_string($id) ? strtolower(trim($id)) : $id,
                $this->input('episodeIds'),
            ),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'episodeIds' => ['required_without:sourceText', 'array', 'min:1', 'max:100'],
            'episodeIds.*' => ['required', 'uuid', 'distinct:strict'],
            'sourceText' => ['required_without:episodeIds', 'string'],
            'nativeLanguage' => ['required', 'string', Rule::in(['en'])],
            'targetLanguage' => ['required', 'string', Rule::in(['ja'])],
            'maxLessonDurationMinutes' => ['sometimes', 'required', 'integer', 'between:1,120'],
            'l1VoiceId' => ['sometimes', 'required', 'string', 'max:255'],
            'jlptLevel' => ['sometimes', 'nullable', 'string', Rule::in(['N5', 'N4', 'N3', 'N2', 'N1'])],
            'speaker1Gender' => ['sometimes', 'required', 'string', Rule::in(['male', 'female'])],
            'speaker2Gender' => ['sometimes', 'required', 'string', Rule::in(['male', 'female'])],
            'speaker1VoiceId' => ['sometimes', 'nullable', 'string', 'max:255'],
            'speaker2VoiceId' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
