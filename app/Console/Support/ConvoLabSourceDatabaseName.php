<?php

namespace App\Console\Support;

final readonly class ConvoLabSourceDatabaseName
{
    private function __construct(public string $value) {}

    public static function fromOption(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : new self($value);
    }
}
