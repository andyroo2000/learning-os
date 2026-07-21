<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FailContentCourseGenerationAction
{
    public function handle(string $courseId, int $attempt, string $message): bool
    {
        $courseId = ContentCourseId::normalize($courseId);
        if ($attempt < 1 || trim($message) === '') {
            throw new InvalidArgumentException('Course generation failure requires an attempt and message.');
        }

        return DB::transaction(function () use ($attempt, $courseId, $message): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if ($course === null || $course->status !== 'generating'
                || (int) $course->generation_attempt !== $attempt) {
                return false;
            }

            $course->status = 'error';
            $course->generation_error_message = trim($message);
            $course->generation_heartbeat_at = now();
            $course->save();

            return true;
        });
    }
}
