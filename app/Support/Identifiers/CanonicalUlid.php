<?php

namespace App\Support\Identifiers;

final class CanonicalUlid
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        // Canonicalize only; callers still own ULID validation.
        // Used both before HTTP validation and in DTO factories for direct action callers.
        return strtolower(trim($value));
    }
}
