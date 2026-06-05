<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Domain\Study\Actions\ListStudyExportCoursesAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportCoursesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_current_courses_for_the_user_in_stable_order(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $firstExportedCourse = Course::factory()->ready()->for($user)->create([
            'title' => 'Second Course',
            'updated_at' => now()->subDay(),
        ]);
        $secondExportedCourse = Course::factory()->draft()->for($user)->create([
            'title' => 'First Course',
            'updated_at' => now(),
        ]);
        $deletedCourse = Course::factory()->for($user)->create([
            'title' => 'Deleted Course',
        ]);

        Course::factory()->for($otherUser)->create([
            'title' => 'Hidden Course',
        ]);
        $deletedCourse->delete();

        $courses = app(ListStudyExportCoursesAction::class)->handle($user->id);

        $this->assertSame(
            [$firstExportedCourse->id, $secondExportedCourse->id],
            $courses->pluck('id')->all(),
        );
        $this->assertSame(
            [CourseStatus::Ready, CourseStatus::Draft],
            $courses->pluck('status')->all(),
        );
    }
}
