<?php

namespace App\Domain\Courses\Actions;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Results\DeleteCourseResult;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\UserHierarchyLock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class DeleteCourseAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly DeleteDeckAction $deleteDeck,
    ) {}

    /**
     * Callers must resolve the course with withTrashed() to preserve retry idempotency.
     * Already-trashed courses are treated as successful no-ops.
     */
    public function handle(Course $course): DeleteCourseResult
    {
        return DB::transaction(function () use ($course): DeleteCourseResult {
            $courseId = $course->getKey();
            $userId = Course::query()
                ->withTrashed()
                ->whereKey($courseId)
                ->value('user_id');

            if ($userId === null) {
                throw (new ModelNotFoundException)->setModel(Course::class, [$courseId]);
            }

            UserHierarchyLock::acquireOrFail((int) $userId, Course::class, $courseId);

            // Acquire hierarchy locks in User -> Course -> Deck -> Card order. Re-resolving
            // under those locks keeps stale/concurrent retries from duplicating tombstones.
            $lockedCourse = Course::query()
                ->withTrashed()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($lockedCourse === null) {
                throw (new ModelNotFoundException)->setModel(Course::class, [$courseId]);
            }

            $course->setRawAttributes($lockedCourse->getAttributes(), true);
            $course->setRelations([]);

            if ($course->trashed()) {
                return DeleteCourseResult::unchanged($course);
            }

            $course->decks()
                ->orderBy('id')
                ->get()
                ->each(fn ($deck) => $this->deleteDeck->handle($deck));

            $course->delete();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $course->user_id,
                    domain: CourseSyncPayload::DOMAIN,
                    resourceType: CourseSyncPayload::RESOURCE_TYPE,
                    resourceId: $course->id,
                    operation: SyncFeedOperation::Delete->value,
                    payload: CourseSyncPayload::fromCourse($course),
                ),
            );

            return DeleteCourseResult::deleted($course);
        });
    }
}
