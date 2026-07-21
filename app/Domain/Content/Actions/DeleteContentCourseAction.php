<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

final class DeleteContentCourseAction
{
    /**
     * A successful hard delete leaves a tombstone so replacement imports cannot resurrect the Course.
     * A retry returns false because ownership can no longer be proven; the original tombstone remains durable.
     */
    public function handle(int $userId, string $convoLabUserId, string $courseId): bool
    {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);

        return DB::transaction(function () use ($userId, $convoLabUserId, $courseId): bool {
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

            $tombstone = ContentCourseTombstone::query()
                ->whereKey($courseId)
                ->lockForUpdate()
                ->first();

            if ($tombstone !== null && (
                $tombstone->user_id !== $userId
                || ! hash_equals($tombstone->convolab_user_id, $convoLabUserId)
            )) {
                return false;
            }

            if ($tombstone === null) {
                $tombstone = new ContentCourseTombstone;
                $tombstone->course_id = $courseId;
                $tombstone->user_id = $userId;
                $tombstone->convolab_user_id = $convoLabUserId;
            }
            $tombstone->deleted_at = now();
            $tombstone->save();

            // This compatibility link table intentionally has no foreign key to content_courses.
            ContentEpisodeCourse::query()
                ->where('convolab_course_id', $courseId)
                ->delete();
            $course->delete();

            return true;
        });
    }
}
