<?php

namespace App\Http\Requests\Study;

use Closure;
use Illuminate\Support\Str;

class StartStudyLessonRequest extends StartStudySessionRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pileId' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! in_array($value, ['uncategorized', 'learn-soon'], true) && ! Str::isUlid($value)) {
                    $fail('Choose a valid study pile.');
                }
            }],
        ];
    }
}
