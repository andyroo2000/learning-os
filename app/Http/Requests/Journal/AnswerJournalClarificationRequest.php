<?php

namespace App\Http\Requests\Journal;

use App\Support\Identifiers\CanonicalUlid;

class AnswerJournalClarificationRequest extends StartJournalClarificationRequest
{
    protected function prepareForValidation(): void
    {
        $question = $this->input('questionId');
        if (is_string($question)) {
            $this->merge(['questionId' => CanonicalUlid::normalize($question)]);
        }
        $answer = $this->input('answer');
        if (is_string($answer)) {
            $this->merge(['answer' => trim($answer)]);
        }
    }

    public function rules(): array
    {
        return [...parent::rules(), 'questionId' => ['required', 'ulid'],
            'answer' => ['required', 'string', 'max:2000', 'not_regex:/\x00/']];
    }
}
