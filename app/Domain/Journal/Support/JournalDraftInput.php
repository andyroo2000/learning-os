<?php

namespace App\Domain\Journal\Support;

use Illuminate\Support\Facades\Validator;

final class JournalDraftInput
{
    public static function normalize(array $input): array
    {
        if (is_string($input['title'] ?? null)) {
            $title = trim($input['title']);
            $input['title'] = $title === '' ? null : $title;
        }

        return $input;
    }

    public static function rules(): array
    {
        return [
            'title' => ['present', 'nullable', 'string', 'max:255'],
            'body' => ['present', 'string', 'max:20000'],
            'entryDate' => ['required', 'date_format:Y-m-d'],
            'expectedVersion' => ['required', 'integer', 'min:0', 'max:2147483646'],
        ];
    }

    public static function validated(array $input): array
    {
        return Validator::make(self::normalize($input), self::rules())->validate();
    }
}
