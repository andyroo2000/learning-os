<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentCourseGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContentCourseGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_EMAIL = 'generation-proxy@example.com';

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convoLabUserId = (string) Str::uuid();
        config()->set('services.convolab.proxy_user_email', self::PROXY_EMAIL);
    }

    public function test_lifecycle_routes_require_authentication_and_write_routes_require_the_proxy_ability(): void
    {
        $id = (string) Str::uuid();
        foreach (['generate', 'reset', 'retry'] as $operation) {
            $this->postJson("/api/convolab/courses/{$id}/{$operation}")->assertUnauthorized();
        }
        $this->getJson("/api/convolab/courses/{$id}/status")->assertUnauthorized();

        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $ordinaryToken = $user->createToken('mobile', ['content:write'])->plainTextToken;
        foreach (['generate', 'reset', 'retry'] as $operation) {
            $this->withToken($ordinaryToken)
                ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
                ->postJson("/api/convolab/courses/{$id}/{$operation}")
                ->assertForbidden();
        }

        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', '')
            ->getJson("/api/convolab/courses/{$id}/status")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
    }

    public function test_generate_atomically_claims_and_queues_a_course_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $course = $this->course($user, [
            'generation_error_message' => 'Old failure.',
            'script_units_json' => [['type' => 'pause', 'seconds' => 1]],
            'audio_url' => '/old.mp3',
        ]);
        $this->authenticateWrite($user);

        $this->postJson('/api/convolab/courses/'.strtoupper($course->id).'/generate')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Course generation started',
                'jobId' => $course->id,
                'courseId' => $course->id,
            ]);

        $course->refresh();
        $this->assertSame('generating', $course->status);
        $this->assertSame(1, $course->generation_attempt);
        $this->assertSame('queued', $course->generation_stage);
        $this->assertSame(0, $course->generation_progress);
        $this->assertNotNull($course->generation_heartbeat_at);
        $this->assertNull($course->generation_error_message);
        $this->assertSame('/old.mp3', $course->audio_url);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
        Queue::assertPushed(
            ProcessContentCourseGeneration::class,
            fn (ProcessContentCourseGeneration $job): bool => $job->courseId === $course->id
                && $job->attempt === 1,
        );

        $this->postJson("/api/convolab/courses/{$course->id}/generate")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Course is already being generated']);
        Queue::assertPushed(ProcessContentCourseGeneration::class, 1);
    }

    public function test_status_reports_durable_progress_and_stuck_state_without_queue_queries(): void
    {
        $user = User::factory()->create();
        $active = $this->course($user, [
            'status' => 'generating',
            'generation_progress' => 35,
            'generation_heartbeat_at' => now(),
        ]);
        $stuck = $this->course($user, [
            'status' => 'generating',
            'generation_progress' => 60,
            'generation_heartbeat_at' => now()->subSeconds(
                ContentCourseGeneration::STALE_AFTER_SECONDS + 1,
            ),
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);

        $this->getJson("/api/convolab/courses/{$active->id}/status")
            ->assertExactJson([
                'status' => 'generating',
                'progress' => 35,
                'isStuck' => false,
                'errorMessage' => null,
            ]);
        $this->getJson("/api/convolab/courses/{$stuck->id}/status")
            ->assertExactJson([
                'status' => 'generating',
                'progress' => 60,
                'isStuck' => true,
                'errorMessage' => null,
            ]);

        $stuck->generation_heartbeat_at = null;
        $stuck->save();
        $this->getJson("/api/convolab/courses/{$stuck->id}/status")
            ->assertJsonPath('isStuck', true);

        $ready = $this->course($user, ['status' => 'ready']);
        $this->getJson("/api/convolab/courses/{$ready->id}/status")
            ->assertExactJson([
                'status' => 'ready',
                'progress' => null,
                'isStuck' => false,
                'errorMessage' => null,
            ]);

        $failed = $this->course($user, [
            'status' => 'error',
            'generation_progress' => 60,
            'generation_error_message' => ContentCourseGeneration::FAILED_MESSAGE,
        ]);
        $this->getJson("/api/convolab/courses/{$failed->id}/status")
            ->assertExactJson([
                'status' => 'error',
                'progress' => 60,
                'isStuck' => false,
                'errorMessage' => ContentCourseGeneration::FAILED_MESSAGE,
            ]);
    }

    public function test_reset_only_invalidates_a_stuck_attempt_and_preserves_generated_outputs(): void
    {
        $user = User::factory()->create();
        $active = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 2,
            'generation_stage' => 'audio',
            'generation_progress' => 60,
            'generation_heartbeat_at' => now(),
        ]);
        $stuck = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 4,
            'generation_stage' => 'audio',
            'generation_progress' => 60,
            'generation_heartbeat_at' => null,
            'generation_error_message' => 'Old failure.',
            'script_units_json' => [['type' => 'pause', 'seconds' => 1]],
            'audio_url' => '/old.mp3',
        ]);
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/courses/{$active->id}/reset")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Course has an active generation job. Cannot reset.']);
        $this->assertSame(2, $active->refresh()->generation_attempt);

        $this->postJson("/api/convolab/courses/{$stuck->id}/reset")
            ->assertOk()
            ->assertExactJson([
                'message' => 'Course reset successfully. You can now start generation again.',
                'courseId' => $stuck->id,
            ]);
        $stuck->refresh();
        $this->assertSame('draft', $stuck->status);
        $this->assertSame(5, $stuck->generation_attempt);
        $this->assertNull($stuck->generation_stage);
        $this->assertNull($stuck->generation_progress);
        $this->assertNull($stuck->generation_heartbeat_at);
        $this->assertNull($stuck->generation_error_message);
        $this->assertSame('/old.mp3', $stuck->audio_url);
        $this->assertSame([['type' => 'pause', 'seconds' => 1]], $stuck->script_units_json);
    }

    #[DataProvider('nonGeneratingStatusProvider')]
    public function test_reset_rejects_courses_not_in_generating_status(string $status): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['status' => $status]);
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/courses/{$course->id}/reset")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Course is not in generating status']);

        $this->assertSame($status, $course->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function nonGeneratingStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'ready' => ['ready'],
            'error' => ['error'],
        ];
    }

    public function test_retry_resumes_audio_when_a_valid_script_reached_the_audio_stage(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $audioFailure = $this->course($user, [
            'status' => 'error',
            'generation_attempt' => 2,
            'generation_stage' => 'audio',
            'generation_progress' => 60,
            'generation_error_message' => 'Audio failed.',
            'script_units_json' => [['type' => 'pause', 'seconds' => 1]],
        ]);
        $scriptFailure = $this->course($user, [
            'status' => 'error',
            'generation_attempt' => 7,
            'generation_stage' => 'script',
            'generation_progress' => 5,
            'generation_error_message' => 'Script failed.',
        ]);
        $ready = $this->course($user, ['status' => 'ready']);
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/courses/{$audioFailure->id}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Course generation retried');
        $audioFailure->refresh();
        $this->assertSame('generating', $audioFailure->status);
        $this->assertSame(3, $audioFailure->generation_attempt);
        $this->assertSame('audio', $audioFailure->generation_stage);
        $this->assertSame(60, $audioFailure->generation_progress);

        $this->postJson("/api/convolab/courses/{$scriptFailure->id}/retry")
            ->assertOk();
        $scriptFailure->refresh();
        $this->assertSame(8, $scriptFailure->generation_attempt);
        $this->assertSame('queued', $scriptFailure->generation_stage);
        $this->assertSame(0, $scriptFailure->generation_progress);

        $this->postJson("/api/convolab/courses/{$ready->id}/retry")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Only courses in error status can be retried']);
        $this->postJson("/api/convolab/courses/{$audioFailure->id}/retry")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Only courses in error status can be retried']);
        Queue::assertPushed(ProcessContentCourseGeneration::class, 2);
    }

    public function test_queue_failure_becomes_an_actionable_error_without_exposing_the_exception(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $this->authenticateWrite($user);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis secret.'));

        $this->postJson("/api/convolab/courses/{$course->id}/generate")
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => ContentCourseGeneration::QUEUE_FAILED_MESSAGE]);

        $course->refresh();
        $this->assertSame('error', $course->status);
        $this->assertSame(1, $course->generation_attempt);
        $this->assertSame(ContentCourseGeneration::QUEUE_FAILED_MESSAGE, $course->generation_error_message);
    }

    public function test_lifecycle_routes_hide_other_owners_and_use_operation_scoped_limiters(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $course = $this->course($owner, [
            'status' => 'error',
            'generation_heartbeat_at' => null,
        ]);
        $this->authenticateWrite($other);

        foreach (['generate', 'reset', 'retry'] as $operation) {
            $this->postJson("/api/convolab/courses/{$course->id}/{$operation}")
                ->assertNotFound();
        }
        $this->getJson("/api/convolab/courses/{$course->id}/status")->assertNotFound();
        Queue::assertNothingPushed();

        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ([
            'generate' => ContentCourseRateLimiter::GENERATION_NAME,
            'retry' => ContentCourseRateLimiter::GENERATION_NAME,
            'reset' => ContentCourseRateLimiter::RESET_NAME,
        ] as $operation => $limiter) {
            $route = $routes->first(fn ($candidate): bool => $candidate->uri() ===
                "api/convolab/courses/{courseId}/{$operation}");
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
    }

    public function test_generation_limiters_have_stable_operation_scoped_user_keys(): void
    {
        $sourceUserId = (string) Str::uuid();
        $request = Request::create('/api/convolab/courses/id/generate', 'POST');
        $request->headers->set('X-Convo-Lab-User-Id', strtoupper($sourceUserId));

        $generation = ContentCourseRateLimiter::forGeneration()->limit($request);
        $reset = ContentCourseRateLimiter::forReset()->limit($request);

        $this->assertSame(10, $generation->maxAttempts);
        $this->assertSame(10, $reset->maxAttempts);
        $this->assertSame(
            ContentCourseRateLimiter::GENERATION_NAME.':user:'.$sourceUserId,
            $generation->key,
        );
        $this->assertSame(
            ContentCourseRateLimiter::RESET_NAME.':user:'.$sourceUserId,
            $reset->key,
        );
        $this->assertNotSame($generation->key, $reset->key);
    }

    public function test_generate_and_retry_share_a_quota_without_starving_reset_or_other_users(): void
    {
        Queue::fake();
        $firstUser = User::factory()->create();
        $firstCourse = $this->course($firstUser);
        $this->authenticateWrite($firstUser);

        $this->postJson("/api/convolab/courses/{$firstCourse->id}/generate")
            ->assertOk();
        for ($attempt = 1; $attempt < 10; $attempt++) {
            $this->postJson("/api/convolab/courses/{$firstCourse->id}/generate")
                ->assertBadRequest();
        }

        $this->postJson("/api/convolab/courses/{$firstCourse->id}/retry")
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
        $this->postJson("/api/convolab/courses/{$firstCourse->id}/reset")
            ->assertBadRequest()
            ->assertJsonPath('message', 'Course has an active generation job. Cannot reset.');

        $this->convoLabUserId = (string) Str::uuid();
        $secondCourse = $this->course($firstUser);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
        $this->postJson("/api/convolab/courses/{$secondCourse->id}/generate")
            ->assertOk();

        Queue::assertPushed(ProcessContentCourseGeneration::class, 2);
    }

    #[DataProvider('protectedLifecycleFieldProvider')]
    public function test_generation_lifecycle_fields_cannot_be_mass_assigned(string $field): void
    {
        $this->expectException(MassAssignmentException::class);

        (new ContentCourse)->fill([$field => 'untrusted']);
    }

    /** @return array<string, array{string}> */
    public static function protectedLifecycleFieldProvider(): array
    {
        return [
            'attempt' => ['generation_attempt'],
            'stage' => ['generation_stage'],
            'progress' => ['generation_progress'],
            'heartbeat' => ['generation_heartbeat_at'],
            'error' => ['generation_error_message'],
        ];
    }

    private function authenticateWrite(User $user): void
    {
        config()->set('services.convolab.proxy_user_email', $user->email);
        $token = $user->createToken('convolab-proxy', ['content:write'])->plainTextToken;
        $this->withToken($token);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
    }

    /** @param array<string, mixed> $attributes */
    private function course(User $user, array $attributes = []): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Generated Course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'fishaudio:ac934b39586e475b83f3277cd97b5cd4',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
            ...$attributes,
        ]);
    }
}
