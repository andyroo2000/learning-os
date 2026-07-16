<?php

namespace App\Support\Identifiers;

final class CanonicalUlid
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        // Canonicalize only; callers still own ULID validation and blank-value rejection.
        // Used both before HTTP validation and in DTO factories for direct action callers.
        return strtolower(trim($value));
    }

    /**
     * @return list<string>
     */
    public static function databaseCandidates(string $value): array
    {
        $canonical = self::normalize($value);

        return array_values(array_unique([$canonical, strtoupper($canonical)]));
    }
}
