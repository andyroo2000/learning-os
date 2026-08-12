<?php

namespace App\Domain\Courses\Actions;

use App\Domain\Courses\Data\UpdateCourseData;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Results\UpdateCourseResult;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\UserHierarchyLock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UpdateCourseAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(Course $course, UpdateCourseData $data): UpdateCourseResult
    {
        return DB::transaction(function () use ($course, $data): UpdateCourseResult {
            $courseId = $course->getKey();
            $userId = Course::query()
                ->withTrashed()
                ->whereKey($courseId)
                ->value('user_id');

            if ($userId === null) {
                throw (new ModelNotFoundException)->setModel(Course::class, [$courseId]);
            }

            UserHierarchyLock::acquireOrFail((int) $userId, Course::class, $courseId);

            // Match account and course-cascade deletion order: User -> Course. Re-resolving
            // the active row after both locks makes a committed tombstone terminal.
            $lockedCourse = Course::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($lockedCourse === null) {
                throw (new ModelNotFoundException)->setModel(Course::class, [$courseId]);
            }

            $course->setRawAttributes($lockedCourse->getAttributes(), true);
            $course->setRelations([]);
            $course->title = $data->title;
            $course->description = $data->description;
            $wasUpdated = $course->isDirty(['title', 'description']);

            $course->saveOrFail();

            if (! $wasUpdated) {
                return UpdateCourseResult::unchanged($course);
            }

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $course->user_id,
                    domain: CourseSyncPayload::DOMAIN,
                    resourceType: CourseSyncPayload::RESOURCE_TYPE,
                    resourceId: $course->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CourseSyncPayload::fromCourse($course),
                ),
            );

            return UpdateCourseResult::updated($course);
        });
    }
}
