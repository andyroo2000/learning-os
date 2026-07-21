<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\UpdateContentCourseData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

final class UpdateContentCourseAction
{
    /**
     * Empty legacy patches still promote imported ownership and advance updated_at.
     */
    public function handle(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        UpdateContentCourseData $data,
    ): bool {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);

        return DB::transaction(function () use ($userId, $convoLabUserId, $courseId, $data): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();

            if ($course === null) {
                return false;
            }

            if ($data->hasTitle) {
                $course->title = $data->title;
            }
            if ($data->hasDescription) {
                $course->description = $data->description;
            }
            if ($data->hasMaxLessonDurationMinutes) {
                $course->max_lesson_duration_minutes = $data->maxLessonDurationMinutes;
            }
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->touch();

            return true;
        });
    }
}
