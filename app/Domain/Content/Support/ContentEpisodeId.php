<?php

namespace App\Domain\Content\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContentEpisodeId
{
    public static function normalize(string $episodeId): string
    {
        $normalized = strtolower(trim($episodeId));
        if (! Str::isUuid($normalized)) {
            throw new InvalidArgumentException('Episode ID must be a UUID.');
        }

        return $normalized;
    }
}
