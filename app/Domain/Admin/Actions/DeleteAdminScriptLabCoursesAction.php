<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Support\AdminCourseLineRenderingStorage;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DeleteAdminScriptLabCoursesAction
{
    public function __construct(private readonly AdminCourseLineRenderingStorage $lineRenderingStorage) {}

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

        [$deleted, $lineRenderingPaths] = DB::transaction(function () use ($courseIds): array {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $courses = ContentCourse::query()
                ->whereIn('id', $courseIds)
                ->lockForUpdate()
                ->get();

            if ($courses->contains(fn (ContentCourse $course): bool => ! $course->is_test_course)) {
                throw AdminMutationException::nonTestScriptLabCourse();
            }

            $existingTombstones = ContentCourseTombstone::query()
                ->whereIn('course_id', $courses->modelKeys())
                ->lockForUpdate()
                ->get()
                ->keyBy('course_id');

            foreach ($courses as $course) {
                $tombstone = $existingTombstones->get($course->id);

                // A restored/imported UUID must not overwrite another owner's deletion record.
                if ($tombstone !== null && (
                    $tombstone->user_id !== $course->user_id
                    || ! hash_equals($tombstone->convolab_user_id, $course->convolab_user_id)
                )) {
                    throw AdminMutationException::scriptLabCourseNotFound();
                }

            }

            $existingIds = $courses->modelKeys();
            $lineRenderingPaths = $this->lineRenderingStorage->ownedPathsForCourses($existingIds);
            $deletedAt = now();
            ContentCourseTombstone::query()->upsert(
                $courses->map(static fn (ContentCourse $course): array => [
                    'course_id' => $course->id,
                    'user_id' => $course->user_id,
                    'convolab_user_id' => $course->convolab_user_id,
                    'deleted_at' => $deletedAt,
                ])->all(),
                ['course_id'],
                ['user_id', 'convolab_user_id', 'deleted_at'],
            );
            ContentEpisodeCourse::query()->whereIn('convolab_course_id', $existingIds)->delete();
            ContentCourse::query()->whereIn('id', $existingIds)->delete();

            return [count($existingIds), $lineRenderingPaths];
        });

        $this->lineRenderingStorage->deletePaths($lineRenderingPaths);

        return $deleted;
    }
}
