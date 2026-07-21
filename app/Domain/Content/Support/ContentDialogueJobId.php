<?php

namespace App\Domain\Content\Support;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class ContentDialogueJobId
{
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if (! Uuid::isValid($value)) {
            throw new InvalidArgumentException('Dialogue generation job ID must be a UUID.');
        }

        return $value;
    }
}
