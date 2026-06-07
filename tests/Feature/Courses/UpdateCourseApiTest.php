<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Support\CourseRateLimiter;
use App\Http\Requests\Courses\StoreCourseRequest;
use App\Http\Resources\Courses\CourseResource;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Courses\Concerns\UsesCourseRateLimitOverrides;
use Tests\TestCase;

class UpdateCourseApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesCourseRateLimitOverrides;

    public function test_it_updates_an_owned_course(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create([
            'title' => 'Japanese Basics',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $course->id)
            ->assertJsonPath('data.title', 'Japanese Travel Foundations')
            ->assertJsonPath('data.description', 'Audio-first course for common travel scenarios.')
            ->assertJsonPath('data.status', CourseStatus::Draft->value)
            ->assertJsonPath('data.native_language', 'en')
            ->assertJsonPath('data.target_language', 'ja')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'description',
                    'status',
                    'native_language',
                    'target_language',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
            ]);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $user->id,
            'domain' => 'courses',
            'resource_type' => 'course',
            'resource_id' => $course->id,
            'operation' => 'update',
        ]);
    }

    public function test_it_normalizes_optional_description(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create([
            'description' => 'Existing description.',
        ]);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => '  Japanese Travel Foundations  ',
            'description' => '   ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.title', 'Japanese Travel Foundations')
            ->assertJsonPath('data.description', null);
    }

    public function test_it_clears_description_when_null_is_sent(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create([
            'title' => 'Japanese Basics',
            'description' => 'Existing description.',
        ]);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => 'Japanese Basics',
            'description' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'description' => null,
        ]);
    }

    public function test_it_is_idempotent_when_metadata_is_unchanged(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $course = Course::factory()->for($user)->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
        ]);

        $course->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CourseResource::make($course)->resolve()['updated_at']);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'updated_at' => $timestamp,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_updates_timestamp_when_metadata_changes(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $course = Course::factory()->for($user)->create([
            'title' => 'Japanese Basics',
            'description' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => 'Japanese Travel Foundations',
            'description' => null,
        ]);

        $course->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CourseResource::make($course)->resolve()['updated_at']);

        $this->assertTrue($course->updated_at->isAfter($timestamp));
    }

    public function test_update_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create([
            'title' => 'Original User Course',
            'description' => null,
        ]);
        $otherUser = User::factory()->create();
        $otherCourse = Course::factory()->for($otherUser)->create([
            'title' => 'Original Other Course',
            'description' => null,
        ]);

        $this->withCourseRateLimitOverride(CourseRateLimiter::UPDATE_NAME, [$user->id, $otherUser->id], function () use ($user, $course, $otherUser, $otherCourse): void {
            foreach ([1, 2] as $attempt) {
                $this
                    ->putJson("/api/courses/{$course->id}", $this->courseUpdatePayload("User Course {$attempt}"))
                    ->assertOk();
            }

            $this->signIn($otherUser);

            $this
                ->putJson("/api/courses/{$otherCourse->id}", $this->courseUpdatePayload('Other User Course'))
                ->assertOk();

            $this->signIn($user);

            $this
                ->putJson("/api/courses/{$course->id}", $this->courseUpdatePayload('Blocked User Course'))
                ->assertTooManyRequests();

            $this
                ->getJson("/api/courses/{$course->id}")
                ->assertOk()
                ->assertJsonPath('data.title', 'User Course 2');

            $this->assertSame('User Course 2', $course->refresh()->title);
            $this->assertSame('Other User Course', $otherCourse->refresh()->title);
        });
    }

    public function test_it_rejects_invalid_input(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => '   ',
            'description' => str_repeat('a', StoreCourseRequest::DESCRIPTION_MAX_LENGTH + 1),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description']);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
        ]);
    }

    public function test_it_rejects_whitespace_input_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/courses/{$course->id}", [
                'title' => '   ',
                'description' => null,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_it_rejects_missing_required_fields(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        $response = $this->putJson("/api/courses/{$course->id}", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_it_hides_another_users_course_before_validating(): void
    {
        $this->signIn();
        $course = Course::factory()->create();

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['title', 'description']);
    }

    public function test_it_returns_not_found_for_a_missing_course(): void
    {
        $this->signIn();

        $response = $this->putJson('/api/courses/'.((string) Str::ulid()), [
            'title' => 'Japanese Travel Foundations',
            'description' => null,
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_course_id(): void
    {
        $this->signIn();

        $response = $this->putJson('/api/courses/not-a-ulid', []);

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $course = Course::factory()->create();

        $response = $this->putJson("/api/courses/{$course->id}", [
            'title' => 'Japanese Travel Foundations',
            'description' => null,
        ]);

        $response->assertUnauthorized();
    }

    public function test_it_does_not_accept_patch_updates(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        $response = $this->patchJson("/api/courses/{$course->id}", [
            'title' => 'Japanese Travel Foundations',
            'description' => null,
        ]);

        $response->assertStatus(405);
    }

    /**
     * @return array<string, string|null>
     */
    private function courseUpdatePayload(string $title): array
    {
        return [
            'title' => $title,
            'description' => null,
        ];
    }
}
