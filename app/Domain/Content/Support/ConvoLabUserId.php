<?php

namespace App\Domain\Content\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConvoLabUserId
{
    public static function normalize(string $userId): string
    {
        $normalized = strtolower(trim($userId));
        if (! Str::isUuid($normalized)) {
            throw new InvalidArgumentException('Convo Lab user ID must be a UUID.');
        }

        return $normalized;
    }

    public static function normalizeOrNull(mixed $userId): ?string
    {
        if (! is_string($userId)) {
            return null;
        }

        $normalized = strtolower(trim($userId));

        return Str::isUuid($normalized) ? $normalized : null;
    }
}
