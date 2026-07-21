<?php

namespace App\Domain\Content\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContentDialogueId
{
    public static function normalize(string $dialogueId): string
    {
        $dialogueId = strtolower(trim($dialogueId));
        if (! Str::isUuid($dialogueId)) {
            throw new InvalidArgumentException('Dialogue ID must be a UUID.');
        }

        return $dialogueId;
    }
}
