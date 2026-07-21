<?php

namespace App\Domain\Content\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContentCourseId
{
    public static function normalize(string $courseId): string
    {
        $normalized = strtolower(trim($courseId));
        if (! Str::isUuid($normalized)) {
            throw new InvalidArgumentException('Course ID must be a UUID.');
        }

        return $normalized;
    }
}
