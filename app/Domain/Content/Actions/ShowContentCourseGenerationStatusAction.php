<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Results\ContentCourseGenerationStatus;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ConvoLabUserId;

class ShowContentCourseGenerationStatusAction
{
    public function handle(int $userId, string $convoLabUserId, string $courseId): ?ContentCourseGenerationStatus
    {
        $course = ContentCourse::query()
            ->select([
                'id',
                'user_id',
                'convolab_user_id',
                'status',
                'generation_progress',
                'generation_heartbeat_at',
                'generation_error_message',
            ])
            ->whereKey(ContentCourseId::normalize($courseId))
            ->where('user_id', $userId)
            ->where('convolab_user_id', ConvoLabUserId::normalize($convoLabUserId))
            ->first();
        if ($course === null) {
            return null;
        }

        return new ContentCourseGenerationStatus(
            status: $course->status,
            progress: $course->generation_progress,
            isStuck: ContentCourseGeneration::isStuck($course),
            errorMessage: $course->generation_error_message,
        );
    }
}
