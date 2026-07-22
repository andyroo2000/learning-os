<?php

namespace App\Domain\Admin\Support;

final class AdminCourseLineAudio
{
    public static function audioUrl(string $courseId, string $renderingId): string
    {
        return "/api/convolab/admin/courses/{$courseId}/line-renderings/{$renderingId}/audio";
    }

    public static function storagePath(string $courseId, string $renderingId): string
    {
        return "admin-course-line-renderings/{$courseId}/{$renderingId}.mp3";
    }

    public static function ownsPath(string $courseId, string $renderingId, ?string $path): bool
    {
        return is_string($path)
            && hash_equals(self::storagePath($courseId, $renderingId), $path);
    }
}
