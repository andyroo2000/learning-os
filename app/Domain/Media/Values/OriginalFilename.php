<?php

namespace App\Domain\Media\Values;

final class OriginalFilename
{
    private function __construct() {}

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $filename = basename(str_replace('\\', '/', trim($value)));

        return in_array($filename, ['', '.', '..'], true) ? null : $filename;
    }
}
