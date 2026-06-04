<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Actions\ListCoursesAction;
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
}
