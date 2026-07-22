<?php

namespace App\Domain\Admin\Support;

final class LegacyJavaScriptValue
{
    public static function isTruthy(mixed $value): bool
    {
        return is_array($value) || is_object($value) || (bool) $value;
    }
}
