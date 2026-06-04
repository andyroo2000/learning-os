<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CoursePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_a_user_to_view_their_own_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();

        $response = Gate::forUser($user)->inspect('view', $course);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_course_when_viewing(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $response = Gate::forUser($user)->inspect('view', $course);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_allows_a_user_to_update_their_own_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();

        $response = Gate::forUser($user)->inspect('update', $course);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_course_when_updating(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $response = Gate::forUser($user)->inspect('update', $course);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_allows_a_user_to_delete_their_own_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();

        $response = Gate::forUser($user)->inspect('delete', $course);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_course_when_deleting(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $response = Gate::forUser($user)->inspect('delete', $course);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
