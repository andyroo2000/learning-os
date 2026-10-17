<?php

namespace App\Domain\Readings\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReadingUploadDocument
{
    public static function validate(array $input, array $images): array
    {
        Validator::make($input, ['pages' => ['required', 'array', 'list'],
            'pages.*' => ['array'], 'pages.*.imageFile' => ['prohibited'],
            'pages.*.imagePath' => ['prohibited'], 'pages.*.imageUrl' => ['prohibited']])->validate();
        $used = [];
        foreach ($input['pages'] as &$page) {
            if (($page['kind'] ?? null) === 'illustration') {
                $number = $page['number'] ?? null;
                if (! is_int($number) || ! isset($images[$number])) {
                    throw ValidationException::withMessages(['images' => 'Every illustration needs an uploaded image keyed by printed page number.']);
                }
                $page['imageFile'] = 'uploaded';
                $used[] = $number;
            }
        }
        unset($page);
        if (count($used) !== count($images)) {
            throw ValidationException::withMessages(['images' => 'An uploaded image does not match an illustration page.']);
        }

        return ReadingDocument::validate($input);
    }
}
