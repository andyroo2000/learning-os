<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Actions\CreateCourseAction;
use App\Domain\Courses\Data\CreateCourseData;
use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Exceptions\CourseConflictException;
use App\Domain\Courses\Models\Course;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CreateCourseActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_draft_course_and_records_sync_feed_entry(): void
    {
        $user = User::factory()->create();

        $result = app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: 'Japanese Travel Foundations',
                nativeLanguage: 'en',
                targetLanguage: 'ja',
                description: 'Audio-first course for common travel scenarios.',
            ),
        );
        $course = $result->course;

        $this->assertTrue($result->wasCreated);
        $this->assertTrue(Str::isUlid($course->id));
        $this->assertSame(CourseStatus::Draft, $course->status);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'status' => CourseStatus::Draft->value,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('courses', $entry->domain);
        $this->assertSame('course', $entry->resource_type);
        $this->assertSame($course->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame([
            'id' => $course->id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'status' => 'draft',
            'native_language' => 'en',
            'target_language' => 'ja',
            'created_at' => $course->created_at?->toJSON(),
            'updated_at' => $course->updated_at?->toJSON(),
            'deleted_at' => null,
        ], $entry->payload);
    }

    public function test_it_uses_a_provided_ulid_and_trims_inputs(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: '  Japanese Travel Foundations  ',
                nativeLanguage: ' en ',
                targetLanguage: ' ja ',
                description: '   ',
                id: strtoupper($id),
            ),
        );
        $course = $result->course;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $course->id);
        $this->assertSame('Japanese Travel Foundations', $course->title);
        $this->assertSame('en', $course->native_language);
        $this->assertSame('ja', $course->target_language);
        $this->assertNull($course->description);
    }

    public function test_it_returns_existing_course_for_idempotent_retries(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCourse = Course::factory()->for($user)->create([
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $result = app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: 'Japanese Travel Foundations',
                nativeLanguage: 'en',
                targetLanguage: 'ja',
                description: '   ',
                id: strtoupper($id),
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingCourse->is($result->course));
        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_idempotent_retry_can_return_existing_course_after_status_changes(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        Course::factory()->ready()->for($user)->create([
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $result = app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: 'Japanese Travel Foundations',
                nativeLanguage: 'en',
                targetLanguage: 'ja',
                id: $id,
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertSame(CourseStatus::Ready, $result->course->status);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_existing_course_when_concurrent_create_wins_the_race(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $raceInsertQuery = null;
        $transactionLevelBeforeAction = DB::transactionLevel();
        $transactionLevelDuringRaceInsert = null;
        $transactionLevelAfterRollback = null;

        DB::listen(function (QueryExecuted $query) use (
            &$inserted,
            &$raceInsertQuery,
            &$transactionLevelDuringRaceInsert,
            $id,
            $user,
        ): void {
            $sql = strtolower($query->sql);

            if (
                $inserted
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'from "courses"')
                || ! in_array($id, $query->bindings, true)
            ) {
                return;
            }

            $inserted = true;
            $raceInsertQuery = $query->sql;
            $transactionLevelDuringRaceInsert = DB::transactionLevel();

            DB::table('courses')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'title' => 'Japanese Travel Foundations',
                'description' => null,
                'status' => CourseStatus::Draft->value,
                'native_language' => 'en',
                'target_language' => 'ja',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $createCourse = CreateCourseAction::withClientIdUniqueConflictHookForTests(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdUniqueConflict: function () use (&$transactionLevelAfterRollback): void {
                $transactionLevelAfterRollback = DB::transactionLevel();
            },
        );

        $result = $createCourse->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: 'Japanese Travel Foundations',
                nativeLanguage: 'en',
                targetLanguage: 'ja',
                id: $id,
            ),
        );

        $this->assertTrue($inserted);
        $this->assertNotNull($raceInsertQuery);
        $this->assertSame($transactionLevelBeforeAction, $transactionLevelDuringRaceInsert);
        $this->assertFalse($result->wasCreated);
        $this->assertSame($transactionLevelBeforeAction, $transactionLevelAfterRollback);
        $this->assertSame($id, $result->course->id);
        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rolls_back_the_course_when_recording_sync_feed_fails(): void
    {
        $user = User::factory()->create();

        $createCourse = new CreateCourseAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sync feed failed.');

        try {
            $createCourse->handle(
                CreateCourseData::fromInput(
                    userId: $user->id,
                    title: 'Japanese Travel Foundations',
                    nativeLanguage: 'en',
                    targetLanguage: 'ja',
                ),
            );
        } finally {
            $this->assertDatabaseCount('courses', 0);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rejects_blank_required_fields(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course title is required.');

        app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: '   ',
                nativeLanguage: 'en',
                targetLanguage: 'ja',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course ID must be a valid ULID.');

        app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: 'Japanese Travel Foundations',
                nativeLanguage: 'en',
                targetLanguage: 'ja',
                id: 'not-a-ulid',
            ),
        );
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        Course::factory()->for($user)->create([
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $this->expectException(CourseConflictException::class);
        $this->expectExceptionMessage('Course ID already exists with different metadata.');

        app(CreateCourseAction::class)->handle(
            CreateCourseData::fromInput(
                userId: $user->id,
                title: 'Spanish Travel Foundations',
                nativeLanguage: 'en',
                targetLanguage: 'es',
                id: $id,
            ),
        );
    }
}
