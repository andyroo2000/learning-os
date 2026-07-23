<?php

namespace App\Domain\Analytics\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ToolAnalyticsProperties implements ValidationRule
{
    private const MAX_PROPERTIES = 16;

    private const MAX_KEY_LENGTH = 40;

    private const MAX_STRING_LENGTH = 120;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || count($value) > self::MAX_PROPERTIES) {
            $fail('The :attribute field must contain at most '.self::MAX_PROPERTIES.' properties.');

            return;
        }

        foreach ($value as $key => $property) {
            if (! $this->validKey($key) || ! $this->validValue($property)) {
                $fail('The :attribute field contains an invalid property.');

                return;
            }
        }
    }

    private function validKey(mixed $key): bool
    {
        if (! is_string($key) && ! is_int($key)) {
            return false;
        }

        $key = (string) $key;

        return $key !== ''
            && mb_strlen($key) <= self::MAX_KEY_LENGTH
            && preg_match('/^[a-z0-9:_-]+$/i', $key) === 1;
    }

    private function validValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && mb_strlen($value) <= self::MAX_STRING_LENGTH;
    }
}
