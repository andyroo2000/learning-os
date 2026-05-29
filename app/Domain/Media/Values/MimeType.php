<?php

namespace App\Domain\Media\Values;

final class MimeType
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        return strtolower(trim(explode(';', trim($value), 2)[0]));
    }

    public static function hasValidNormalizedShape(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9!#$&\-^_]*\/[a-z0-9][a-z0-9!#$&\-^_.+]*$/', $value) === 1;
    }
}
