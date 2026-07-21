<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\AssembleContentCourseAudioAction;
use App\Domain\Content\Actions\FailContentCourseGenerationAction;
use App\Domain\Content\Actions\GenerateContentCourseScriptAction;
use App\Domain\Content\Actions\ProcessContentCourseGenerationAction;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentCourseGeneration;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessContentCourseGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convoLabUserId = (string) Str::uuid();
    }

    public function test_job_has_a_bounded_retry_envelope_and_normalized_unique_identity(): void
    {
        $courseId = (string) Str::uuid();
        $job = new ProcessContentCourseGeneration(strtoupper($courseId), 4);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame($courseId, $job->courseId);
        $this->assertSame(4, $job->attempt);
        $this->assertSame($courseId.':4', $job->uniqueId());
        $this->assertNotSame(
            $job->uniqueId(),
            (new ProcessContentCourseGeneration($courseId, 5))->uniqueId(),
        );
        $this->assertSame(ContentCourseGeneration::JOB_TRIES, $job->tries);
        $this->assertSame(ContentCourseGeneration::JOB_TIMEOUT_SECONDS, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([30], $job->backoff());
        $this->assertSame('default', $job->queue);
        $this->assertGreaterThan($job->timeout, ContentCourseGeneration::STALE_AFTER_SECONDS);
    }

    public function test_job_rejects_malformed_identity_and_non_positive_attempts(): void
    {
        foreach ([['not-a-uuid', 1], [(string) Str::uuid(), 0]] as [$courseId, $attempt]) {
            try {
                new ProcessContentCourseGeneration($courseId, $attempt);
                $this->fail('Expected malformed generation job input to fail.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_full_generation_advances_durable_progress_and_completes_the_attempt(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 3,
            'generation_stage' => 'queued',
            'generation_progress' => 0,
        ]);
        $script = $this->mock(GenerateContentCourseScriptAction::class);
        $script->shouldReceive('handle')
            ->once()
            ->with($user->id, $this->convoLabUserId, $course->id, 3)
            ->andReturnUsing(fn (): ContentCourse => $course->fresh());
        $audio = $this->mock(AssembleContentCourseAudioAction::class);
        $audio->shouldReceive('handle')
            ->once()
            ->with($user->id, $this->convoLabUserId, $course->id, 3)
            ->andReturnUsing(fn (): ContentCourse => $course->fresh());

        (new ProcessContentCourseGeneration($course->id, 3))
            ->handle(app(ProcessContentCourseGenerationAction::class));
        (new ProcessContentCourseGeneration($course->id, 3))
            ->handle(app(ProcessContentCourseGenerationAction::class));

        $course->refresh();
        $this->assertSame('ready', $course->status);
        $this->assertSame(3, $course->generation_attempt);
        $this->assertSame('complete', $course->generation_stage);
        $this->assertSame(100, $course->generation_progress);
        $this->assertNotNull($course->generation_heartbeat_at);
        $this->assertNull($course->generation_error_message);
    }

    public function test_audio_retry_skips_script_generation_and_completes(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 8,
            'generation_stage' => 'audio',
            'generation_progress' => 60,
        ]);
        $this->mock(GenerateContentCourseScriptAction::class)
            ->shouldNotReceive('handle');
        $this->mock(AssembleContentCourseAudioAction::class)
            ->shouldReceive('handle')
            ->once()
            ->with($user->id, $this->convoLabUserId, $course->id, 8)
            ->andReturnUsing(fn (): ContentCourse => $course->fresh());

        app(ProcessContentCourseGenerationAction::class)->handle($course->id, 8);

        $course->refresh();
        $this->assertSame('ready', $course->status);
        $this->assertSame('complete', $course->generation_stage);
        $this->assertSame(100, $course->generation_progress);
    }

    public function test_stale_attempt_does_no_provider_work(): void
    {
        $user = User::factory()->create();
        $stale = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 5,
            'generation_stage' => 'queued',
            'generation_progress' => 0,
        ]);
        $this->mock(GenerateContentCourseScriptAction::class)->shouldNotReceive('handle');
        $this->mock(AssembleContentCourseAudioAction::class)->shouldNotReceive('handle');

        app(ProcessContentCourseGenerationAction::class)->handle(strtoupper($stale->id), 4);

        $this->assertSame('queued', $stale->fresh()->generation_stage);
        $this->assertSame(0, $stale->fresh()->generation_progress);
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_course_does_no_provider_work(string $status): void
    {
        $user = User::factory()->create();
        $terminal = $this->course($user, [
            'status' => $status,
            'generation_attempt' => 2,
        ]);
        $this->mock(GenerateContentCourseScriptAction::class)->shouldNotReceive('handle');
        $this->mock(AssembleContentCourseAudioAction::class)->shouldNotReceive('handle');

        app(ProcessContentCourseGenerationAction::class)->handle($terminal->id, 2);

        $this->assertSame($status, $terminal->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function terminalStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'ready' => ['ready'],
            'error' => ['error'],
        ];
    }

    public function test_missing_course_does_no_provider_work(): void
    {
        $this->mock(GenerateContentCourseScriptAction::class)->shouldNotReceive('handle');
        $this->mock(AssembleContentCourseAudioAction::class)->shouldNotReceive('handle');

        app(ProcessContentCourseGenerationAction::class)->handle((string) Str::uuid(), 1);

        $this->assertDatabaseCount('content_courses', 0);
    }

    public function test_reset_during_script_generation_prevents_audio_and_completion(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 6,
            'generation_stage' => 'queued',
            'generation_progress' => 0,
        ]);
        $this->mock(GenerateContentCourseScriptAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function () use ($course): null {
                DB::table('content_courses')->where('id', $course->id)->update([
                    'status' => 'draft',
                    'generation_attempt' => 7,
                    'generation_stage' => null,
                    'generation_progress' => null,
                    'generation_heartbeat_at' => null,
                ]);

                return null;
            });
        $this->mock(AssembleContentCourseAudioAction::class)->shouldNotReceive('handle');

        app(ProcessContentCourseGenerationAction::class)->handle($course->id, 6);

        $course->refresh();
        $this->assertSame('draft', $course->status);
        $this->assertSame(7, $course->generation_attempt);
        $this->assertNull($course->generation_stage);
        $this->assertNull($course->generation_progress);
    }

    public function test_failed_callback_only_fails_the_current_active_attempt_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $current = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 3,
            'generation_stage' => 'audio',
            'generation_progress' => 60,
        ]);
        $stale = $this->course($user, [
            'status' => 'generating',
            'generation_attempt' => 5,
            'generation_stage' => 'queued',
        ]);

        (new ProcessContentCourseGeneration($current->id, 3))
            ->failed(new RuntimeException('Provider secret.'));
        (new ProcessContentCourseGeneration($current->id, 3))
            ->failed(new RuntimeException('Repeated failure.'));
        (new ProcessContentCourseGeneration($stale->id, 4))
            ->failed(new RuntimeException('Stale failure.'));

        $current->refresh();
        $this->assertSame('error', $current->status);
        $this->assertSame(ContentCourseGeneration::FAILED_MESSAGE, $current->generation_error_message);
        $this->assertSame('audio', $current->generation_stage);
        $this->assertSame(60, $current->generation_progress);
        $this->assertNotNull($current->generation_heartbeat_at);
        $this->assertSame('generating', $stale->fresh()->status);
        $this->assertNull($stale->fresh()->generation_error_message);
    }

    public function test_actions_reject_invalid_attempts_and_failure_messages(): void
    {
        foreach ([
            fn () => app(ProcessContentCourseGenerationAction::class)->handle((string) Str::uuid(), 0),
            fn () => app(FailContentCourseGenerationAction::class)->handle((string) Str::uuid(), 0, 'failed'),
            fn () => app(FailContentCourseGenerationAction::class)->handle((string) Str::uuid(), 1, '  '),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected invalid generation lifecycle input to fail.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
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
