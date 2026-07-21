<?php

namespace App\Domain\Content\Support;

final class ContentCourseAudio
{
    public static function audioUrl(string $courseId): string
    {
        return "/api/convolab/courses/{$courseId}/audio";
    }

    public static function storagePath(string $courseId, int $revision): string
    {
        return "content-courses/{$courseId}/generation-{$revision}.mp3";
    }

    public static function ownsPath(string $courseId, ?string $path): bool
    {
        return is_string($path)
            && preg_match(
                '/^content-courses\/'.preg_quote($courseId, '/').'\/generation-[1-9][0-9]*\.mp3$/',
                $path,
            ) === 1;
    }
}
