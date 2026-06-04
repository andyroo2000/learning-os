<?php

namespace App\Domain\Courses\Actions;

use App\Domain\Courses\Data\CreateCourseData;
use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Exceptions\CourseConflictException;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Results\CreateCourseResult;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\IntegrityConstraintViolation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class CreateCourseAction
{
    private ?Closure $afterClientIdUniqueConflict = null;

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * @internal Test-only race hook; see tests/Feature/Courses/CreateCourseActionTest.php.
     */
    public static function withClientIdUniqueConflictHookForTests(
        RecordSyncFeedEntryAction $recordSyncFeedEntry,
        Closure $afterClientIdUniqueConflict,
    ): self {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Course creation race hooks may only be used in tests.');
        }

        $action = new self($recordSyncFeedEntry);
        $action->afterClientIdUniqueConflict = $afterClientIdUniqueConflict;

        return $action;
    }

    public function handle(CreateCourseData $data): CreateCourseResult
    {
        if ($data->title === '') {
            throw new InvalidArgumentException('Course title is required.');
        }

        if ($data->nativeLanguage === '') {
            throw new InvalidArgumentException('Course native language is required.');
        }

        if ($data->targetLanguage === '') {
            throw new InvalidArgumentException('Course target language is required.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Course ID must be a valid ULID.');
        }

        $description = self::normalizedDescription($data->description);

        if ($data->id !== null) {
            // Common retry path only; primary-key recovery below handles concurrent inserts after this read.
            $existingCourse = Course::withTrashed()->find($data->id);

            if ($existingCourse !== null) {
                return CreateCourseResult::existing($this->matchingExistingCourse($existingCourse, $data, $description));
            }
        }

        return $this->createNewCourse($data, $description);
    }

    /**
     * Manual transaction control keeps PostgreSQL primary-key race recovery outside
     * a failed transaction before refetching the winning course.
     */
    private function createNewCourse(CreateCourseData $data, ?string $description): CreateCourseResult
    {
        $course = new Course([
            'title' => $data->title,
            'description' => $description,
            'native_language' => $data->nativeLanguage,
            'target_language' => $data->targetLanguage,
        ]);
        $course->user_id = $data->userId;
        $course->status = CourseStatus::Draft;

        if ($data->id !== null) {
            $course->id = $data->id;
        }

        DB::beginTransaction();

        try {
            $course->save();
            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $course->user_id,
                    domain: CourseSyncPayload::DOMAIN,
                    resourceType: CourseSyncPayload::RESOURCE_TYPE,
                    resourceId: $course->id,
                    operation: SyncFeedOperation::Create->value,
                    payload: CourseSyncPayload::fromCourse($course),
                ),
            );
        } catch (QueryException $exception) {
            DB::rollBack();

            if ($data->id === null || ! IntegrityConstraintViolation::matchesPrimaryKey($exception, 'courses')) {
                throw $exception;
            }

            if ($this->afterClientIdUniqueConflict !== null) {
                ($this->afterClientIdUniqueConflict)($data, $exception);
            }

            $existingCourse = Course::withTrashed()->find($data->id);

            if ($existingCourse === null) {
                throw $exception;
            }

            return CreateCourseResult::existing($this->matchingExistingCourse($existingCourse, $data, $description));
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::commit();

        return CreateCourseResult::created($course);
    }

    private static function normalizedDescription(?string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    private function matchingExistingCourse(Course $course, CreateCourseData $data, ?string $description): Course
    {
        if ($course->trashed()) {
            throw CourseConflictException::deleted($course);
        }

        // Status is server-controlled after creation and must not block idempotent retries.
        if (
            $course->user_id !== $data->userId
            || $course->title !== $data->title
            || $course->description !== $description
            || $course->native_language !== $data->nativeLanguage
            || $course->target_language !== $data->targetLanguage
        ) {
            throw CourseConflictException::conflict($course);
        }

        return $course;
    }
}
