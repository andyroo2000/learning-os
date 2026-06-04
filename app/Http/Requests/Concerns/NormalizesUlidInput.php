<?php

namespace App\Http\Requests\Concerns;

use App\Support\Identifiers\CanonicalUlid;

trait NormalizesUlidInput
{
    /**
     * @param  array<string, mixed>  $target
     */
    protected function mergeNormalizedUlidInput(array &$target, string $key): void
    {
        $value = $this->normalizeUlidInput($key);

        if (is_string($value)) {
            $target[$key] = $value;
        }
    }

    protected function normalizeUlidInput(string $key): mixed
    {
        return $this->normalizeUlidValue($this->input($key));
    }

    protected function normalizeUlidValue(mixed $value): mixed
    {
        return is_string($value) ? CanonicalUlid::normalize($value) : $value;
    }
}
