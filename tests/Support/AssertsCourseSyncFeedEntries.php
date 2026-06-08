<?php

namespace Tests\Support;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;

trait AssertsCourseSyncFeedEntries
{
    protected function assertCourseSyncPayloadRecorded(Course $course, SyncFeedOperation $operation): SyncFeedEntry
    {
        $entry = SyncFeedEntry::query()
            ->where('domain', CourseSyncPayload::DOMAIN)
            ->where('resource_type', CourseSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $course->id)
            ->where('operation', $operation->value)
            ->sole();

        $this->assertSame($course->user_id, $entry->user_id);
        $this->assertEquals(CourseSyncPayload::fromCourse($course), $entry->payload);

        return $entry;
    }
}
