<?php

namespace App\Domain\Courses\Actions;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Results\DeleteCourseResult;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
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
            // Re-resolve under a row lock so stale and concurrent DELETE retries cannot
            // append duplicate course or descendant tombstones.
            $lockedCourse = Course::query()
                ->withTrashed()
                ->whereKey($course->getKey())
                ->lockForUpdate()
                ->firstOrFail();
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
