<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Actions\UpdateCourseAction;
use App\Domain\Courses\Data\UpdateCourseData;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateCourseActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_course_metadata_and_records_sync_feed_entry(): void
    {
        $course = Course::factory()->for($this->signIn())->create([
            'title' => 'Japanese Basics',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $result = app(UpdateCourseAction::class)->handle(
            $course,
            UpdateCourseData::fromInput(
                title: 'Japanese Travel Foundations',
                description: 'Audio-first course for common travel scenarios.',
            ),
        );
        $updatedCourse = $result->course;

        $this->assertTrue($result->wasUpdated);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'user_id' => $course->user_id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($course->user_id, $entry->user_id);
        $this->assertSame(CourseSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CourseSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame($course->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame(CourseSyncPayload::fromCourse($updatedCourse), $entry->payload);
    }

    public function test_it_trims_text_inputs(): void
    {
        $course = Course::factory()->for($this->signIn())->create([
            'title' => 'Japanese Basics',
            'description' => null,
        ]);

        $result = app(UpdateCourseAction::class)->handle(
            $course,
            UpdateCourseData::fromInput(
                title: '  Japanese Travel Foundations  ',
                description: '  Audio-first course for common travel scenarios.  ',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('Japanese Travel Foundations', $result->course->title);
        $this->assertSame('Audio-first course for common travel scenarios.', $result->course->description);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_stores_blank_description_as_null(): void
    {
        $course = Course::factory()->for($this->signIn())->create([
            'description' => 'Existing description.',
        ]);

        $result = app(UpdateCourseAction::class)->handle(
            $course,
            UpdateCourseData::fromInput(
                title: 'Japanese Travel Foundations',
                description: '   ',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertNull($result->course->description);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_marks_unchanged_when_normalized_metadata_matches_the_existing_course(): void
    {
        $course = Course::factory()->for($this->signIn())->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
        ]);

        $result = app(UpdateCourseAction::class)->handle(
            $course,
            UpdateCourseData::fromInput(
                title: '  Japanese Travel Foundations  ',
                description: '  Audio-first course for common travel scenarios.  ',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_blank_title(): void
    {
        $course = Course::factory()->for($this->signIn())->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course title is required.');

        app(UpdateCourseAction::class)->handle(
            $course,
            UpdateCourseData::fromInput(
                title: '   ',
                description: null,
            ),
        );
    }
}
