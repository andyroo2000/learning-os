<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

class ResetContentCourseGenerationAction
{
    public function handle(int $userId, string $convoLabUserId, string $courseId): ?ContentCourse
    {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);

        return DB::transaction(function () use ($courseId, $convoLabUserId, $userId): ?ContentCourse {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                return null;
            }
            if ($course->status !== 'generating') {
                throw ContentCourseGenerationConflictException::notGenerating();
            }
            if (! ContentCourseGeneration::isStuck($course)) {
                throw ContentCourseGenerationConflictException::activeGeneration();
            }

            $course->status = 'draft';
            $course->generation_attempt = ((int) $course->generation_attempt) + 1;
            $course->generation_stage = null;
            $course->generation_progress = null;
            $course->generation_heartbeat_at = null;
            $course->generation_error_message = null;
            $course->save();

            return $course;
        });
    }
}
