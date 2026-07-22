<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\GenerateAdminCourseAudioAction;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Actions\QueueContentCourseGenerationAction;
use App\Domain\Content\Data\ContentCourseScriptUnits;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentCourseGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AdminCourseAudioApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_route_enforces_write_scope_actor_uuid_and_operation_limiter(): void
    {
        $courseId = (string) Str::uuid();

        $this->withToken($this->proxyToken(['admin:read']))
            ->postJson("/api/convolab/admin/courses/{$courseId}/generate-audio")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($this->proxyToken(['admin:write']))
            ->postJson("/api/convolab/admin/courses/{$courseId}/generate-audio")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');
        $this->app['auth']->forgetGuards();

        $this->writeRequest()
            ->postJson('/api/convolab/admin/courses/not-a-uuid/generate-audio')
            ->assertNotFound();

        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/courses/{courseId}/generate-audio',
        );
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::COURSE_AUDIO_GENERATE,
            $route->gatherMiddleware(),
        );
    }

    public function test_it_queues_audio_from_pipeline_script_units_and_claims_the_canonical_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $units = $this->scriptUnits();
        $course = $this->course($user, [
            'script_json' => [
                '_pipelineStage' => 'script',
                '_exchanges' => [['legacy' => true]],
                '_scriptUnits' => $units,
            ],
            'script_units_json' => [['type' => 'marker', 'label' => 'Old']],
            'audio_url' => '/old.mp3',
            'timing_data' => [['start' => 0]],
            'generation_attempt' => 4,
            'generation_error_message' => 'Old failure.',
        ]);

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-audio")
            ->assertOk()
            ->assertExactJson([
                'message' => 'Audio generation started',
                'jobId' => $course->id,
                'courseId' => $course->id,
            ]);

        $course->refresh();
        $this->assertSame($units, $course->script_units_json);
        $this->assertSame('generating', $course->status);
        $this->assertSame(5, $course->generation_attempt);
        $this->assertSame('audio', $course->generation_stage);
        $this->assertSame(60, $course->generation_progress);
        $this->assertNotNull($course->generation_heartbeat_at);
        $this->assertNull($course->generation_error_message);
        $this->assertSame('/old.mp3', $course->audio_url);
        $this->assertSame([['start' => 0]], $course->timing_data);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
        Queue::assertPushed(
            ProcessContentCourseGeneration::class,
            fn (ProcessContentCourseGeneration $job): bool => $job->courseId === $course->id
                && $job->attempt === 5,
        );
    }

    public function test_it_accepts_the_legacy_flat_script_format(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $units = $this->scriptUnits();
        $course = $this->course($user, ['script_json' => $units]);

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-audio")
            ->assertOk();

        $this->assertSame($units, $course->fresh()->script_units_json);
        Queue::assertPushed(ProcessContentCourseGeneration::class, 1);
    }

    public function test_missing_and_malformed_scripts_use_legacy_errors_without_queueing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $missing = $this->course($user, ['script_json' => null]);

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$missing->id}/generate-audio")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'No script data found. Generate script first.']);

        foreach ([
            [],
            ['_pipelineStage' => 'exchanges', '_exchanges' => []],
            ['_pipelineStage' => 'script', '_scriptUnits' => []],
            ['_pipelineStage' => 'script', '_scriptUnits' => [['type' => 'pause', 'seconds' => 0]]],
            ['_pipelineStage' => 'script', '_scriptUnits' => ['not-an-object']],
        ] as $scriptJson) {
            $course = $this->course($user, ['script_json' => $scriptJson]);

            $this->writeRequest()
                ->postJson("/api/convolab/admin/courses/{$course->id}/generate-audio")
                ->assertBadRequest()
                ->assertExactJson([
                    'message' => 'Script data is not in the correct format for audio generation. Generate script first.',
                ]);
            $this->assertSame('draft', $course->fresh()->status);
        }

        Queue::assertNothingPushed();
    }

    public function test_active_generation_is_rejected_without_replacing_the_owned_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $course = $this->course($user, [
            'script_json' => $this->scriptUnits(),
            'status' => 'generating',
            'generation_attempt' => 3,
            'generation_stage' => 'audio',
        ]);

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-audio")
            ->assertConflict()
            ->assertExactJson(['message' => 'Course is already being generated']);

        $this->assertSame(3, $course->fresh()->generation_attempt);
        Queue::assertNothingPushed();
    }

    public function test_concurrent_script_change_maps_to_a_409_without_queueing_stale_audio(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $course = $this->course($user, ['script_json' => $this->scriptUnits()]);
        $canonicalQueue = app(QueueContentCourseGenerationAction::class);
        $newerScript = [
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [['newer' => true]],
        ];
        $this->mock(
            QueueContentCourseGenerationAction::class,
            function (MockInterface $mock) use ($canonicalQueue, $course, $newerScript): void {
                $mock->shouldReceive('handleAudioOnly')
                    ->once()
                    ->andReturnUsing(function (
                        int $userId,
                        string $convoLabUserId,
                        string $courseId,
                        ContentCourseScriptUnits $units,
                        string $scriptHash,
                    ) use ($canonicalQueue, $course, $newerScript) {
                        ContentCourse::query()->whereKey($course->id)->update([
                            'script_json' => $newerScript,
                        ]);

                        return $canonicalQueue->handleAudioOnly(
                            $userId,
                            $convoLabUserId,
                            $courseId,
                            $units,
                            $scriptHash,
                        );
                    });
            },
        );

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-audio")
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Course script changed while audio generation was being queued',
            ]);

        $course->refresh();
        $this->assertSame($newerScript, $course->script_json);
        $this->assertSame('draft', $course->status);
        $this->assertSame(0, $course->generation_attempt);
        $this->assertNull($course->script_units_json);
        Queue::assertNothingPushed();
    }

    public function test_queue_failure_becomes_a_recoverable_error_without_leaking_the_exception(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['script_json' => $this->scriptUnits()]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis secret.'));

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-audio")
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Course generation could not be queued. Please try again.']);

        $course->refresh();
        $this->assertSame('error', $course->status);
        $this->assertSame(1, $course->generation_attempt);
        $this->assertSame(
            'Course generation could not be queued. Please try again.',
            $course->generation_error_message,
        );
    }

    public function test_missing_courses_and_malformed_direct_ids_do_not_queue_work(): void
    {
        Queue::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(GenerateAdminCourseAudioAction::class)->handle('bad-id');
            $this->fail('Expected malformed course ID to fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->writeRequest()
            ->postJson('/api/convolab/admin/courses/'.Str::uuid().'/generate-audio')
            ->assertNotFound();
        Queue::assertNothingPushed();
    }

    /** @return non-empty-list<array<string, string>> */
    private function scriptUnits(): array
    {
        return [
            ['type' => 'marker', 'label' => 'Lesson Start'],
            ['type' => 'narration_L1', 'text' => 'Welcome.', 'voiceId' => 'fishaudio:narrator'],
        ];
    }

    private function writeRequest(): static
    {
        return $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid());
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'proxy@example.com'],
            ['name' => 'Proxy', 'password' => 'unused'],
        );

        return $user->createToken('convolab-proxy', $abilities)->plainTextToken;
    }

    /** @param array<string, mixed> $overrides */
    private function course(User $user, array $overrides = []): ContentCourse
    {
        return ContentCourse::query()->forceCreate(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => true,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 15,
            'l1_voice_id' => 'fishaudio:narrator',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
