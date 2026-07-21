<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\AssembleContentCourseAudioAction;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseAudio;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use App\Support\Audio\AudioScriptUnit;
use App\Support\Audio\AudioTrackAssembler;
use App\Support\Audio\AudioTrackAssemblyResult;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AssembleContentCourseAudioActionTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convoLabUserId = (string) Str::uuid();
        config(['content_courses.audio_disk' => 'course-audio-test']);
        Storage::fake('course-audio-test');
    }

    public function test_it_persists_revision_scoped_audio_and_removes_the_previous_owned_file(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $previousPath = ContentCourseAudio::storagePath($course->id, 2);
        $course->forceFill([
            'generation_revision' => 2,
            'audio_storage_path' => $previousPath,
            'audio_url' => ContentCourseAudio::audioUrl($course->id),
            'timing_data' => [['unitIndex' => 1, 'startTime' => 0, 'endTime' => 500]],
            'approx_duration_seconds' => 1,
        ])->save();
        Storage::disk('course-audio-test')->put($previousPath, 'old-audio');

        $this->expectAssembly(function (array $units, string $disk, string $path): AudioTrackAssemblyResult {
            $this->assertSame('course-audio-test', $disk);
            $this->assertSame(ContentCourseDefaults::NARRATOR_VOICE_EN, $units[0]->audioVoiceId());
            $this->assertSame(
                'fishaudio:9639f090aa6346329d7d3aca7e6b7226',
                $units[1]->audioVoiceId(),
            );
            Storage::disk($disk)->put($path, 'new-audio');

            return $this->assemblyResult($path);
        });

        $assembled = app(AssembleContentCourseAudioAction::class)->handle(
            $user->id,
            strtoupper($this->convoLabUserId),
            strtoupper($course->id),
        );

        $this->assertNotNull($assembled);
        $newPath = ContentCourseAudio::storagePath($course->id, 3);
        $this->assertSame(3, $assembled->generation_revision);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $assembled->source_system);
        $this->assertSame($newPath, $assembled->audio_storage_path);
        $this->assertSame(ContentCourseAudio::audioUrl($course->id), $assembled->audio_url);
        $this->assertSame(12, $assembled->approx_duration_seconds);
        $this->assertSame($this->assemblyResult($newPath)->timingData, $assembled->timing_data);
        Storage::disk('course-audio-test')->assertExists($newPath);
        Storage::disk('course-audio-test')->assertMissing($previousPath);
    }

    public function test_assembly_failure_preserves_previously_published_audio(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $previousPath = ContentCourseAudio::storagePath($course->id, 1);
        $course->forceFill([
            'generation_revision' => 1,
            'audio_storage_path' => $previousPath,
            'audio_url' => ContentCourseAudio::audioUrl($course->id),
            'timing_data' => [['unitIndex' => 0]],
            'approx_duration_seconds' => 5,
        ])->save();
        Storage::disk('course-audio-test')->put($previousPath, 'old-audio');
        $this->mock(AudioTrackAssembler::class)
            ->shouldReceive('assemble')
            ->once()
            ->andThrow(new RuntimeException('provider unavailable'));

        try {
            app(AssembleContentCourseAudioAction::class)->handle(
                $user->id,
                $this->convoLabUserId,
                $course->id,
            );
            $this->fail('Expected Course audio assembly to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $course->refresh();
        $this->assertSame(2, $course->generation_revision);
        $this->assertSame($previousPath, $course->audio_storage_path);
        $this->assertSame(5, $course->approx_duration_seconds);
        Storage::disk('course-audio-test')->assertExists($previousPath);
        Storage::disk('course-audio-test')->assertMissing(
            ContentCourseAudio::storagePath($course->id, 2),
        );
    }

    public function test_a_newer_revision_rejects_the_result_and_removes_its_orphaned_file(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $previousPath = ContentCourseAudio::storagePath($course->id, 1);
        $course->forceFill([
            'generation_revision' => 1,
            'audio_storage_path' => $previousPath,
            'audio_url' => ContentCourseAudio::audioUrl($course->id),
        ])->save();
        Storage::disk('course-audio-test')->put($previousPath, 'old-audio');
        $this->expectAssembly(function (array $units, string $disk, string $path) use ($course): AudioTrackAssemblyResult {
            Storage::disk($disk)->put($path, 'stale-audio');
            DB::table('content_courses')->where('id', $course->id)->increment('generation_revision');

            return $this->assemblyResult($path);
        });

        try {
            app(AssembleContentCourseAudioAction::class)->handle(
                $user->id,
                $this->convoLabUserId,
                $course->id,
            );
            $this->fail('Expected stale Course audio assembly to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Course changed while its audio was being assembled.',
                $exception->getMessage(),
            );
        }

        $course->refresh();
        $this->assertSame(3, $course->generation_revision);
        $this->assertSame($previousPath, $course->audio_storage_path);
        Storage::disk('course-audio-test')->assertExists($previousPath);
        Storage::disk('course-audio-test')->assertMissing(
            ContentCourseAudio::storagePath($course->id, 2),
        );
    }

    public function test_invalid_or_missing_courses_never_start_audio_assembly(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $assembler = $this->mock(AudioTrackAssembler::class);
        $assembler->shouldNotReceive('assemble');

        $this->assertNull(app(AssembleContentCourseAudioAction::class)->handle(
            User::factory()->create()->id,
            $this->convoLabUserId,
            $course->id,
        ));
        $this->assertNull(app(AssembleContentCourseAudioAction::class)->handle(
            $user->id,
            $this->convoLabUserId,
            (string) Str::uuid(),
        ));

        $course->script_units_json = [['type' => 'pause', 'seconds' => 0]];
        $course->save();
        try {
            app(AssembleContentCourseAudioAction::class)->handle(
                $user->id,
                $this->convoLabUserId,
                $course->id,
            );
            $this->fail('Expected the malformed persisted script to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $course->refresh()->generation_revision);
        }
    }

    public function test_audio_storage_path_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new ContentCourse)->fill(['audio_storage_path' => 'untrusted.mp3']);
    }

    private function expectAssembly(callable $callback): void
    {
        $this->mock(AudioTrackAssembler::class)
            ->shouldReceive('assemble')
            ->once()
            ->withArgs(function (
                array $units,
                string $disk,
                string $path,
                string $temporaryPrefix,
                string $label,
            ): bool {
                $this->assertContainsOnlyInstancesOf(AudioScriptUnit::class, $units);
                $this->assertSame('learning-os-content-course', $temporaryPrefix);
                $this->assertSame('Course audio', $label);

                return true;
            })
            ->andReturnUsing($callback);
    }

    private function assemblyResult(string $path): AudioTrackAssemblyResult
    {
        return new AudioTrackAssemblyResult(
            storagePath: $path,
            durationSeconds: 12,
            timingData: [
                ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 4000],
                ['unitIndex' => 1, 'startTime' => 4000, 'endTime' => 12000],
            ],
            metadata: [
                'unitCount' => 2,
                'spokenUnitCount' => 2,
                'pauseUnitCount' => 0,
                'uniqueSynthesisCount' => 2,
                'reusedSynthesisCount' => 0,
            ],
        );
    }

    private function course(User $user): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Audio Course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'en-US-Neural2-J',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
            'speaker1_voice_id' => 'ja-JP-Neural2-B',
            'speaker2_voice_id' => 'ja-JP-Neural2-C',
            'script_units_json' => [
                ['type' => 'narration_L1', 'text' => 'Listen.', 'voiceId' => 'en-US-Neural2-J'],
                [
                    'type' => 'L2', 'text' => '猫です。', 'reading' => 'ねこです。',
                    'translation' => 'It is a cat.', 'voiceId' => 'ja-JP-Neural2-B', 'speed' => 1,
                ],
            ],
        ]);
    }
}
