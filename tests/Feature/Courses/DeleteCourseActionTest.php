<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Actions\DeleteCourseAction;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class DeleteCourseActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_a_course_and_records_sync_feed_entry(): void
    {
        $course = Course::factory()->for($this->signIn())->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $result = app(DeleteCourseAction::class)->handle($course);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($course, $result->course);
        $this->assertSoftDeleted('courses', [
            'id' => $course->id,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertNotNull($course->deleted_at);
        $this->assertSame($course->user_id, $entry->user_id);
        $this->assertSame(CourseSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CourseSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame($course->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame(CourseSyncPayload::fromCourse($course), $entry->payload);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $course = Course::factory()->for($this->signIn())->create();
        $deleteCourse = new DeleteCourseAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $deleteCourse->handle($course);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_no_ops_when_the_course_is_already_soft_deleted(): void
    {
        $course = Course::factory()->for($this->signIn())->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $course->delete();
            $originalDeletedAt = $course->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $result = app(DeleteCourseAction::class)->handle($course);

            $this->assertFalse($result->wasDeleted);
            $this->assertSame($course, $result->course);
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        } finally {
            Carbon::setTestNow();
        }
    }
}
