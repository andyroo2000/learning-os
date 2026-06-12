<?php

namespace App\Domain\Study\Support;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StudyListScopeFilter
{
    private function __construct() {}

    public static function normalizeId(?string $value, string $field, string $context): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = CanonicalUlid::normalize($value);
        $prefix = $context === '' ? '' : "{$context} ";

        if ($normalized === '') {
            throw new InvalidArgumentException("{$prefix}{$field} filter must not be blank when provided.");
        }

        if (! Str::isUlid($normalized)) {
            throw new InvalidArgumentException("{$prefix}{$field} filter must be a valid ULID.");
        }

        return $normalized;
    }
}
