<?php

namespace App\Domain\Study\Support;

use Illuminate\Support\Str;

final class StudyActivitySessionId
{
    public static function normalize(string $value): string
    {
        $value = trim($value);

        if (Str::isUlid($value)) {
            return strtoupper($value);
        }

        if (Str::isUuid($value)) {
            return strtolower($value);
        }

        return $value;
    }
}
