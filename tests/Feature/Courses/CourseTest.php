<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_courses_table_has_current_and_status_filtered_list_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('courses'));

        $this->assertNotEmpty($indexes->filter(
            fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'deleted_at', 'updated_at', 'id']
        ));
        $this->assertNotEmpty($indexes->filter(
            fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'status', 'deleted_at', 'updated_at', 'id']
        ));
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

        $this->assertInstanceOf(User::class, $course->user);
        $this->assertSame($course->user_id, $course->user->id);
    }

    public function test_course_has_many_decks(): void
    {
        $course = Course::factory()->create();
        $firstDeck = Deck::factory()->create([
            'user_id' => $course->user_id,
            'course_id' => $course->id,
        ]);
        $secondDeck = Deck::factory()->create([
            'user_id' => $course->user_id,
            'course_id' => $course->id,
        ]);
        Deck::factory()->create(['user_id' => $course->user_id]);

        $this->assertSame(
            [$firstDeck->id, $secondDeck->id],
            $course->decks()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_user_id_is_not_mass_assignable(): void
    {
        $course = Course::make([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertNull($course->user_id);
    }

    public function test_status_is_not_mass_assignable(): void
    {
        $course = Course::make([
            'status' => CourseStatus::Ready,
        ]);

        $this->assertNull($course->status);
    }

    public function test_factory_states_create_expected_statuses(): void
    {
        $draft = Course::factory()->draft()->create();
        $generating = Course::factory()->generating()->create();
        $ready = Course::factory()->ready()->create();
        $error = Course::factory()->error()->create();

        $this->assertSame(CourseStatus::Draft, $draft->status);
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

    public function test_course_language_pair_cannot_be_changed_after_creation(): void
    {
        $course = Course::factory()->create([
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $course->native_language = 'fr';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Course language pair cannot be changed.');

        try {
            $course->save();
        } finally {
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'native_language' => 'en',
                'target_language' => 'ja',
            ]);
        }
    }

    public function test_course_target_language_cannot_be_changed_after_creation(): void
    {
        $course = Course::factory()->create([
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $course->target_language = 'it';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Course language pair cannot be changed.');

        try {
            $course->save();
        } finally {
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'native_language' => 'en',
                'target_language' => 'ja',
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

    public function test_course_can_be_force_deleted(): void
    {
        $course = Course::factory()->create();

        $course->forceDelete();

        $this->assertDatabaseMissing('courses', [
            'id' => $course->id,
        ]);
    }

    public function test_redeleting_a_soft_deleted_course_preserves_deleted_timestamp(): void
    {
        $course = Course::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:00'));

        try {
            $course->delete();
            $originalDeletedAt = $course->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:01'));

            Course::withTrashed()->findOrFail($course->id)->delete();

            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
