<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Support\CourseRateLimiter;
use App\Http\Requests\Courses\StoreCourseRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCourseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_course(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/courses', [
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Japanese Travel Foundations')
            ->assertJsonPath('data.description', 'Audio-first course for common travel scenarios.')
            ->assertJsonPath('data.status', 'draft')
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

        $this->assertTrue(Str::isUlid($response->json('data.id')));
        $this->assertDatabaseHas('courses', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'status' => CourseStatus::Draft->value,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $user->id,
            'domain' => 'courses',
            'resource_type' => 'course',
            'resource_id' => $response->json('data.id'),
            'operation' => 'create',
        ]);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/courses', [
            'id' => strtoupper($id),
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', strtolower($id));

        $this->assertDatabaseHas('courses', [
            'id' => strtolower($id),
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
        ]);
    }

    public function test_it_normalizes_padded_uppercase_client_provided_ulid_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/courses', [
                'id' => '  '.strtoupper($id).'  ',
                'title' => 'Japanese Travel Foundations',
                'native_language' => 'en',
                'target_language' => 'ja',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('courses', [
            'id' => $id,
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
        ]);
    }

    public function test_it_trims_client_provided_ulid_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/courses', [
                'id' => "  {$id}  ",
                'title' => 'Japanese Travel Foundations',
                'native_language' => 'en',
                'target_language' => 'ja',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('courses', [
            'id' => $id,
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
        ]);
    }

    public function test_it_lowercases_client_provided_ulid_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/courses', [
                'id' => strtoupper($id),
                'title' => 'Japanese Travel Foundations',
                'native_language' => 'en',
                'target_language' => 'ja',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('courses', [
            'id' => $id,
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
        ]);
    }

    public function test_it_returns_existing_course_for_idempotent_retries(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ];

        $firstResponse = $this->postJson('/api/courses', $payload);
        $secondResponse = $this->postJson('/api/courses', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.id', $id);
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.title', 'Japanese Travel Foundations');

        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_create_is_rate_limited_by_user(): void
    {
        $testBucket = 'test-'.Str::ulid();
        $clientIp = '127.0.0.1';
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

        $restoreCourseCreateLimiter = function (): void {
            $limiter = CourseRateLimiter::create();
            RateLimiter::for(CourseRateLimiter::CREATE_NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $testRateLimitKey = static fn (mixed $userId, ?string $ip): string => $testBucket.'|'.CourseRateLimiter::keyFor(CourseRateLimiter::CREATE_NAME, $userId, $ip);
        $userKey = $testRateLimitKey($user->id, $clientIp);
        $otherUserKey = $testRateLimitKey($otherUser->id, $clientIp);

        try {
            RateLimiter::for(CourseRateLimiter::CREATE_NAME, function (Request $request) use ($testRateLimitKey): Limit {
                return Limit::perMinute(2)->by($testRateLimitKey(
                    $request->user()?->getAuthIdentifier(),
                    $request->ip(),
                ));
            });

            foreach ([1, 2] as $attempt) {
                $this
                    ->postJson('/api/courses', $this->courseCreatePayload("User Course {$attempt}"))
                    ->assertCreated();
            }

            $this->signIn($otherUser);

            $this
                ->postJson('/api/courses', $this->courseCreatePayload('Other User Course'))
                ->assertCreated();

            $this->signIn($user);

            $this
                ->postJson('/api/courses', $this->courseCreatePayload('Blocked User Course'))
                ->assertTooManyRequests();

            $this
                ->getJson('/api/courses')
                ->assertOk()
                ->assertJsonCount(2, 'data');

            $this->assertSame(2, Course::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, Course::query()->where('user_id', $otherUser->id)->count());
            $this->assertDatabaseMissing('courses', [
                'user_id' => $user->id,
                'title' => 'Blocked User Course',
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreCourseCreateLimiter();
        }
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        Course::factory()->for($user)->create([
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response = $this->postJson('/api/courses', [
            'id' => $id,
            'title' => 'Spanish Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'es',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Course ID already exists with different metadata.')
            ->assertJsonPath('reason', 'course_id_conflict');
    }

    public function test_it_returns_gone_for_owned_soft_deleted_course_conflicts(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $course = Course::factory()->for($user)->create([
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);
        $course->delete();

        $response = $this->postJson('/api/courses', [
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Course ID belongs to a deleted course.')
            ->assertJsonPath('reason', 'course_deleted');
    }

    public function test_it_hides_idempotent_retries_for_other_users_courses(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        Course::factory()->for($otherUser)->create([
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response = $this->postJson('/api/courses', [
            'id' => $id,
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseMissing('courses', [
            'id' => $id,
            'user_id' => $user->id,
        ]);
    }

    public function test_it_normalizes_optional_description(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/courses', [
            'title' => '  Japanese Travel Foundations  ',
            'description' => '   ',
            'native_language' => ' en ',
            'target_language' => ' ja ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Japanese Travel Foundations')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.native_language', 'en')
            ->assertJsonPath('data.target_language', 'ja');
    }

    public function test_it_normalizes_language_codes_without_global_trim_middleware(): void
    {
        $user = $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/courses', [
                'title' => 'Japanese Travel Foundations',
                'native_language' => ' EN ',
                'target_language' => ' JA ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.native_language', 'en')
            ->assertJsonPath('data.target_language', 'ja');

        $courseId = $response->json('data.id');

        $this->assertDatabaseHas('courses', [
            'id' => $courseId,
            'user_id' => $user->id,
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $user->id,
            'resource_id' => $courseId,
            'payload->native_language' => 'en',
            'payload->target_language' => 'ja',
        ]);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/courses', [
            'id' => 'not-a-ulid',
            'title' => '   ',
            'description' => str_repeat('a', StoreCourseRequest::DESCRIPTION_MAX_LENGTH + 1),
            'native_language' => '',
            'target_language' => '',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'title', 'description', 'native_language', 'target_language']);

        $this->assertDatabaseCount('courses', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson('/api/courses', [
            'title' => 'Japanese Travel Foundations',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('courses', 0);
    }

    public function test_it_rejects_whitespace_input_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/courses', [
                'title' => '   ',
                'native_language' => '   ',
                'target_language' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'native_language', 'target_language']);

        $this->assertDatabaseCount('courses', 0);
    }

    public function test_it_accepts_a_sanctum_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile-test')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->postJson('/api/courses', [
                'title' => 'Japanese Travel Foundations',
                'native_language' => 'en',
                'target_language' => 'ja',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Japanese Travel Foundations');

        $this->assertDatabaseHas('courses', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'title' => 'Japanese Travel Foundations',
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function courseCreatePayload(string $title): array
    {
        return [
            'title' => $title,
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'ja',
        ];
    }
}
