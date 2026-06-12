<?php

namespace App\Http\Requests\Concerns;

trait NormalizesStringInputs
{
    /**
     * Trim string inputs before validation while preserving arrays and other invalid shapes.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $lowercase
     * @param  list<string>  $blankToNull
     */
    protected function mergeNormalizedStringInputs(array $keys, array $lowercase = [], array $blankToNull = []): void
    {
        $lowercaseKeys = array_flip($lowercase);
        $blankToNullKeys = array_flip($blankToNull);

        $this->mergeStringInputsUsing(
            $keys,
            function (string $value, string $key) use ($lowercaseKeys, $blankToNullKeys): ?string {
                $value = trim($value);

                if (isset($lowercaseKeys[$key])) {
                    $value = mb_strtolower($value);
                }

                return $value === '' && isset($blankToNullKeys[$key])
                    ? null
                    : $value;
            },
        );
    }

    /**
     * Normalize string inputs with a field-specific callback while preserving invalid shapes.
     *
     * @param  list<string>  $keys
     * @param  callable(string, string): string|null  $normalize
     */
    protected function mergeStringInputsUsing(array $keys, callable $normalize): void
    {
        $normalized = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $normalized[$key] = $normalize($value, $key);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    protected function nullableString(string $key): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists($key, $validated) || $validated[$key] === null || $validated[$key] === '') {
            return null;
        }

        return (string) $validated[$key];
    }
}
