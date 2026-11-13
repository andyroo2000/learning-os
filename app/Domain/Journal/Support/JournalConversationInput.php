<?php

namespace App\Domain\Journal\Support;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class JournalConversationInput
{
    public static function normalize(array $input): array
    {
        foreach (['id', 'questionId'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = CanonicalUlid::normalize($input[$field]);
            }
        }

        return $input;
    }

    public static function rules(): array
    {
        return [
            'id' => ['required', 'ulid'],
            'questionId' => ['required', 'ulid'],
            'body' => ['required', 'string', 'max:20000', 'regex:/\S/u', 'not_regex:/\x00/'],
        ];
    }

    public static function validated(array $input): array
    {
        return Validator::make(self::normalize($input), self::rules())->validate();
    }
}
