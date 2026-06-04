<?php

namespace App\Support\DateTime;

class StrictIsoDateTime
{
    public static function matches(string $value): bool
    {
        return preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
            $value,
        ) === 1;
    }
}
