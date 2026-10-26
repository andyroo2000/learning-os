<?php

namespace App\Domain\Study\Support;

use Illuminate\Support\Facades\Validator;

final class StudyPileInput
{
    public static function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:512'],
            'position' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public static function normalize(array $input): array
    {
        foreach (['title', 'source_reference'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        return $input;
    }

    /** Direct callers share the same validation and sparse-update semantics as HTTP. */
    public static function validated(array $input): array
    {
        $data = Validator::make(self::normalize($input), self::rules())->validate();
        foreach (['position' => 'int', 'is_active' => 'bool'] as $key => $type) {
            if (array_key_exists($key, $data)) {
                settype($data[$key], $type);
            }
        }

        return $data;
    }
}
