<?php

namespace App\Domain\Journal\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class JournalClarificationResult
{
    public static function validated(array $result): array
    {
        $data = Validator::make(['result' => $result], [
            'result' => ['required', 'array:resolved,question,confirmedMeaning'],
            'result.resolved' => ['required', 'boolean:strict'],
            'result.question' => ['present', 'nullable', 'array:prompt,choices'],
            'result.question.prompt' => ['required_with:result.question', 'string', 'max:500', 'not_regex:/\x00/'],
            'result.question.choices' => ['required_with:result.question', 'array', 'list', 'min:2', 'max:4'],
            'result.question.choices.*' => ['required', 'string', 'max:200', 'distinct:strict', 'not_regex:/\x00/'],
            'result.confirmedMeaning' => ['present', 'string', 'max:10000', 'not_regex:/\x00/'],
        ])->validate()['result'];
        if ($data['resolved'] !== ($data['question'] === null)) {
            throw ValidationException::withMessages(['result.question' => 'Unresolved meaning requires a question; resolved meaning cannot have one.']);
        }
        if (! $data['resolved'] && $data['confirmedMeaning'] !== '') {
            throw ValidationException::withMessages(['result.confirmedMeaning' => 'Unresolved meaning must not be treated as confirmed.']);
        }

        return $data;
    }
}
