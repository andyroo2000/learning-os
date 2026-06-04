<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_courses_table_has_shared_core_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('courses', [
            'id',
            'user_id',
            'title',
            'description',
            'status',
            'native_language',
            'target_language',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_course_can_be_created_with_a_factory(): void
    {
        $course = Course::factory()->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'status' => CourseStatus::Ready,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $this->assertIsString($course->id);
        $this->assertTrue(Str::isUlid($course->id));
        $this->assertSame(CourseStatus::Ready, $course->status);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'user_id' => $course->user_id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'status' => CourseStatus::Ready->value,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);
    }

    public function test_course_belongs_to_a_user(): void
    {
        $course = Course::factory()->create();

        $this->assertIsInt($course->user_id);
        $this->assertEquals($course->user_id, $course->user->id);
    }

    public function test_factory_states_create_expected_statuses(): void
    {
        $generating = Course::factory()->generating()->create();
        $ready = Course::factory()->ready()->create();
        $error = Course::factory()->error()->create();

        $this->assertSame(CourseStatus::Generating, $generating->status);
        $this->assertSame(CourseStatus::Ready, $ready->status);
        $this->assertSame(CourseStatus::Error, $error->status);
    }

    public function test_course_owner_cannot_be_changed_after_creation(): void
    {
        $course = Course::factory()->create();
        $originalUserId = $course->user_id;
        $course->user_id = User::factory()->create()->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Course owner cannot be changed.');

        try {
            $course->save();
        } finally {
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'user_id' => $originalUserId,
            ]);
        }
    }

    public function test_description_is_optional(): void
    {
        $course = Course::factory()->create([
            'description' => null,
        ]);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'description' => null,
        ]);
    }

    public function test_course_can_be_soft_deleted(): void
    {
        $course = Course::factory()->create();

        $course->delete();

        $this->assertSoftDeleted('courses', [
            'id' => $course->id,
        ]);
    }
}
