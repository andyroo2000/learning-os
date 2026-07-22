<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\QueueContentCourseGenerationAction;
use App\Domain\Content\Actions\StartContentCourseGenerationAction;
use App\Domain\Content\Data\ContentCourseScriptUnits;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentCourseGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class StartContentCourseAudioGenerationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_audio_start_normalizes_ids_persists_units_and_dispatches_after_commit(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $scriptJson = ['_pipelineStage' => 'script', '_scriptUnits' => $this->payload()];
        $course = $this->course($user, $sourceUserId, ['script_json' => $scriptJson]);
        $units = ContentCourseScriptUnits::fromPayload($this->payload());

        $result = app(QueueContentCourseGenerationAction::class)->handleAudioOnly(
            $user->id,
            strtoupper($sourceUserId),
            strtoupper($course->id),
            $units,
            $this->hash($scriptJson),
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->audioOnly);
        $this->assertSame(1, $result->attempt);
        $course->refresh();
        $this->assertSame($this->payload(), $course->script_units_json);
        $this->assertSame('generating', $course->status);
        $this->assertSame('audio', $course->generation_stage);
        Queue::assertPushed(
            ProcessContentCourseGeneration::class,
            fn (ProcessContentCourseGeneration $job): bool => $job->courseId === $course->id
                && $job->attempt === 1,
        );
    }

    public function test_stale_script_snapshot_rejects_audio_start_without_mutation_or_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $oldScript = ['_pipelineStage' => 'script', '_scriptUnits' => $this->payload()];
        $course = $this->course($user, $sourceUserId, ['script_json' => $oldScript]);
        $units = ContentCourseScriptUnits::fromPayload($this->payload());
        $course->script_json = ['_pipelineStage' => 'exchanges', '_exchanges' => []];
        $course->save();

        try {
            app(QueueContentCourseGenerationAction::class)->handleAudioOnly(
                $user->id,
                $sourceUserId,
                $course->id,
                $units,
                $this->hash($oldScript),
            );
            $this->fail('Expected the stale script snapshot to be rejected.');
        } catch (ContentCourseGenerationConflictException $exception) {
            $this->assertSame(
                'Course script changed while audio generation was being queued',
                $exception->getMessage(),
            );
        }

        $course->refresh();
        $this->assertSame('draft', $course->status);
        $this->assertSame(0, $course->generation_attempt);
        $this->assertNull($course->script_units_json);
        Queue::assertNothingPushed();
    }

    public function test_direct_boundary_rejects_invalid_hash_before_database_work(): void
    {
        $units = ContentCourseScriptUnits::fromPayload($this->payload());
        $queries = $this->captureQueries(function () use ($units): void {
            try {
                app(StartContentCourseGenerationAction::class)->handleAudioOnly(
                    1,
                    (string) Str::uuid(),
                    (string) Str::uuid(),
                    $units,
                    'not-a-hash',
                    static function (): void {},
                );
                $this->fail('Expected an invalid hash to fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Expected course script hash must be lowercase SHA-256.',
                    $exception->getMessage(),
                );
            }
        });

        $this->assertSame([], $queries);
    }

    public function test_audio_start_hides_courses_owned_by_another_user_or_source_identity(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $script = ['_pipelineStage' => 'script', '_scriptUnits' => $this->payload()];
        $course = $this->course($owner, $sourceUserId, ['script_json' => $script]);
        $units = ContentCourseScriptUnits::fromPayload($this->payload());

        foreach ([
            [$other->id, $sourceUserId],
            [$owner->id, (string) Str::uuid()],
        ] as [$userId, $convoLabUserId]) {
            $this->assertNull(app(QueueContentCourseGenerationAction::class)->handleAudioOnly(
                $userId,
                $convoLabUserId,
                $course->id,
                $units,
                $this->hash($script),
            ));
        }

        $this->assertSame('draft', $course->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_script_unit_collection_bounds_and_normalizes_the_shared_audio_contract(): void
    {
        $units = ContentCourseScriptUnits::fromPayload([
            ['type' => 'pause', 'seconds' => 1],
        ]);
        $this->assertSame('pause', $units->payload()[0]['type']);
        $this->assertEquals(1.0, $units->payload()[0]['seconds']);
        $maximum = ContentCourseScriptUnits::fromPayload(array_fill(
            0,
            ContentCourseScriptUnits::MAX_UNITS,
            ['type' => 'pause', 'seconds' => 1],
        ));
        $this->assertCount(ContentCourseScriptUnits::MAX_UNITS, $maximum->units);

        foreach ([
            null,
            [],
            [['type' => 'pause', 'seconds' => 0]],
            array_fill(0, ContentCourseScriptUnits::MAX_UNITS + 1, ['type' => 'pause', 'seconds' => 1]),
        ] as $payload) {
            try {
                ContentCourseScriptUnits::fromPayload($payload);
                $this->fail('Expected invalid script units to fail.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function captureQueries(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $callback();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /** @return non-empty-list<array<string, string>> */
    private function payload(): array
    {
        return [
            ['type' => 'marker', 'label' => 'Start'],
            ['type' => 'narration_L1', 'text' => 'Welcome.', 'voiceId' => 'fishaudio:narrator'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $overrides */
    private function course(User $user, string $sourceUserId, array $overrides = []): ContentCourse
    {
        return ContentCourse::query()->forceCreate(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $sourceUserId,
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
        ], $overrides));
    }
}
