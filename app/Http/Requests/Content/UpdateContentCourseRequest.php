<?php

namespace App\Http\Requests\Content;

class UpdateContentCourseRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'maxLessonDurationMinutes' => ['sometimes', 'required', 'integer', 'between:1,120'],
        ];
    }
}
