<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\DeleteAdminCourseLineRenderingAction;
use App\Domain\Admin\Actions\SynthesizeAdminCourseLineAction;
use App\Domain\Admin\Data\SynthesizeAdminCourseLineData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use App\Support\Audio\AudioSpeechGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

final class AdminCourseLineRenderingActionTest extends TestCase
{
    use RefreshDatabase;

    private const VOICE_ID = 'fishaudio:0123456789abcdef0123456789abcdef';

    public function test_synthesis_rejects_a_malformed_course_id_before_queries_or_provider_spend(): void
    {
        Storage::fake('media');
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(SynthesizeAdminCourseLineAction::class)->handle(
                'not-a-uuid',
                SynthesizeAdminCourseLineData::fromInput([
                    'text' => 'Text',
                    'voiceId' => self::VOICE_ID,
                    'unitIndex' => 0,
                ]),
            );
            $this->fail('Expected malformed course ID to fail.');
        } catch (InvalidArgumentException) {
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame([], $queries);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_delete_rejects_a_malformed_rendering_id_before_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(DeleteAdminCourseLineRenderingAction::class)->handle(
                '01234567-89ab-4def-8123-456789abcdef',
                'not-a-uuid',
            );
            $this->fail('Expected malformed rendering ID to fail.');
        } catch (AdminMutationException $exception) {
            $this->assertSame('Rendering not found', $exception->getMessage());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame([], $queries);
    }

    public function test_synthesis_rechecks_course_existence_after_provider_work(): void
    {
        config()->set('content_courses.audio_disk', 'media');
        Storage::fake('media');
        $course = $this->course();
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock) use ($course): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function () use ($course): string {
                    $course->delete();

                    return 'ID3audio';
                });
        });

        try {
            app(SynthesizeAdminCourseLineAction::class)->handle(
                $course->id,
                $this->data(),
            );
            $this->fail('Expected deleted course to fail.');
        } catch (AdminMutationException $exception) {
            $this->assertSame('Course not found', $exception->getMessage());
        }

        $this->assertDatabaseCount('admin_course_line_renderings', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_synthesis_discards_audio_when_persistence_fails(): void
    {
        config()->set('content_courses.audio_disk', 'media');
        Storage::fake('media');
        $course = $this->course();
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->once()->andReturn('ID3audio');
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_admin_line_rendering
            BEFORE INSERT ON admin_course_line_renderings
            BEGIN
                SELECT RAISE(ABORT, 'forced persistence failure');
            END
            SQL);

        try {
            app(SynthesizeAdminCourseLineAction::class)->handle($course->id, $this->data());
            $this->fail('Expected persistence to fail.');
        } catch (QueryException) {
            // The storage assertion below is the contract under test.
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_admin_line_rendering');
        }

        $this->assertDatabaseCount('admin_course_line_renderings', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    private function data(): SynthesizeAdminCourseLineData
    {
        return SynthesizeAdminCourseLineData::fromInput([
            'text' => 'Text',
            'voiceId' => self::VOICE_ID,
            'unitIndex' => 0,
        ]);
    }

    private function course(): ContentCourse
    {
        $user = User::factory()->create();

        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Action test course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => true,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 15,
            'l1_voice_id' => self::VOICE_ID,
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
