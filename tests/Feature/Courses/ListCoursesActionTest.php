<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Actions\ListCoursesAction;
use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCoursesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();

        Course::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $courses = app(ListCoursesAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $courses->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $courses->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $user = User::factory()->create();

        Course::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $courses = app(ListCoursesAction::class)->handle($user->id);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $courses->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $courses->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();

        Course::factory()->count(2)->for($user)->create();

        $courses = app(ListCoursesAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(0),
        );

        $this->assertSame(1, $courses->perPage());
        $this->assertCount(1, $courses->items());
    }

    public function test_it_filters_courses_by_status(): void
    {
        $user = User::factory()->create();
        $readyCourse = Course::factory()->ready()->for($user)->create();

        Course::factory()->draft()->for($user)->create();
        Course::factory()->ready()->for(User::factory()->create())->create();

        $courses = app(ListCoursesAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(10),
            CourseStatus::Ready,
        );

        $this->assertCount(1, $courses->items());
        $this->assertSame($readyCourse->id, $courses->items()[0]->id);
    }

    public function test_it_filters_courses_by_language_pair(): void
    {
        $user = User::factory()->create();
        $matchingCourse = Course::factory()->for($user)->create([
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        Course::factory()->for($user)->create([
            'native_language' => 'en',
            'target_language' => 'it',
        ]);
        Course::factory()->for($user)->create([
            'native_language' => 'es',
            'target_language' => 'ja',
        ]);
        Course::factory()->for(User::factory()->create())->create([
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $courses = app(ListCoursesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(10),
            nativeLanguage: 'en',
            targetLanguage: 'ja',
        );

        $this->assertCount(1, $courses->items());
        $this->assertSame($matchingCourse->id, $courses->items()[0]->id);
    }
}
