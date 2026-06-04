<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowCourseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_an_owned_course(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->ready()->for($user)->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response = $this->getJson("/api/courses/{$course->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $course->id,
                    'title' => 'Japanese Travel Foundations',
                    'description' => 'Audio-first course for common travel scenarios.',
                    'status' => CourseStatus::Ready->value,
                    'native_language' => 'en',
                    'target_language' => 'ja',
                    'created_at' => $course->created_at?->toJSON(),
                    'updated_at' => $course->updated_at?->toJSON(),
                ],
            ]);
    }

    public function test_it_shows_an_owned_course_with_an_uppercase_route_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        $response = $this->getJson('/api/courses/'.strtoupper($course->id));

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $course->id);
    }

    public function test_it_hides_another_users_course(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($otherUser)->create();

        $response = $this->getJson("/api/courses/{$course->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_course(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/courses/'.((string) Str::ulid()));

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_course_id(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/courses/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_soft_deleted_course(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        $course->delete();

        $response = $this->getJson("/api/courses/{$course->id}");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $course = Course::factory()->create();

        $response = $this->getJson("/api/courses/{$course->id}");

        $response->assertUnauthorized();
    }
}
