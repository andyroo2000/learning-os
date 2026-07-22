<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DeleteAdminScriptLabCoursesAction
{
    /** @param list<string> $courseIds */
    public function handle(array $courseIds): int
    {
        if (count($courseIds) < 1 || count($courseIds) > 100) {
            throw new InvalidArgumentException('Script Lab course IDs must contain 1 to 100 UUIDs.');
        }
        foreach ($courseIds as $courseId) {
            if (! is_string($courseId)) {
                throw new InvalidArgumentException('Script Lab course IDs must be UUID strings.');
            }
        }

        $courseIds = array_map(ContentCourseId::normalize(...), $courseIds);
        if (count(array_unique($courseIds)) !== count($courseIds)) {
            throw new InvalidArgumentException('Script Lab course IDs must be distinct.');
        }

        return DB::transaction(function () use ($courseIds): int {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $courses = ContentCourse::query()
                ->whereIn('id', $courseIds)
                ->lockForUpdate()
                ->get();

            if ($courses->contains(fn (ContentCourse $course): bool => ! $course->is_test_course)) {
                throw AdminMutationException::nonTestScriptLabCourse();
            }

            foreach ($courses as $course) {
                $tombstone = ContentCourseTombstone::query()
                    ->whereKey($course->id)
                    ->lockForUpdate()
                    ->first();
                if ($tombstone !== null && (
                    $tombstone->user_id !== $course->user_id
                    || ! hash_equals($tombstone->convolab_user_id, $course->convolab_user_id)
                )) {
                    throw AdminMutationException::scriptLabCourseNotFound();
                }

                $tombstone ??= new ContentCourseTombstone;
                $tombstone->course_id = $course->id;
                $tombstone->user_id = $course->user_id;
                $tombstone->convolab_user_id = $course->convolab_user_id;
                $tombstone->deleted_at = now();
                $tombstone->save();
            }

            $existingIds = $courses->modelKeys();
            ContentEpisodeCourse::query()->whereIn('convolab_course_id', $existingIds)->delete();
            ContentCourse::query()->whereIn('id', $existingIds)->delete();

            return count($existingIds);
        });
    }
}
