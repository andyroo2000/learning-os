<?php

namespace Tests\Feature\Content;

use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Admin\Support\AdminCourseLineAudio;
use App\Domain\Content\Actions\DeleteContentCourseAction;
use App\Domain\Content\Actions\UpdateContentCourseAction;
use App\Domain\Content\Data\UpdateContentCourseData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentCourseWriteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_normalizes_ids_and_promotes_imported_ownership_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $course = $this->courseFor($user, [
            'title' => 'Original',
            'description' => 'Original description.',
            'max_lesson_duration_minutes' => 30,
        ]);

        $updated = app(UpdateContentCourseAction::class)->handle(
            $user->id,
            strtoupper($course->convolab_user_id),
            strtoupper($course->id),
            UpdateContentCourseData::fromInput([
                'title' => '  Updated  ',
                'description' => null,
                'maxLessonDurationMinutes' => '+45',
            ]),
        );

        $this->assertTrue($updated);
        $course->refresh();
        $this->assertSame('Updated', $course->title);
        $this->assertNull($course->description);
        $this->assertSame(45, $course->max_lesson_duration_minutes);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
    }

    public function test_update_presence_flags_preserve_untouched_fields(): void
    {
        $data = UpdateContentCourseData::fromInput(['description' => '  Changed.  ']);

        $this->assertFalse($data->hasTitle);
        $this->assertTrue($data->hasDescription);
        $this->assertSame('Changed.', $data->description);
        $this->assertFalse($data->hasMaxLessonDurationMinutes);
        $this->assertNull($data->maxLessonDurationMinutes);
    }

    public function test_update_accepts_duration_boundaries(): void
    {
        $this->assertSame(
            1,
            UpdateContentCourseData::fromInput(['maxLessonDurationMinutes' => 1])
                ->maxLessonDurationMinutes,
        );
        $this->assertSame(
            120,
            UpdateContentCourseData::fromInput(['maxLessonDurationMinutes' => 120])
                ->maxLessonDurationMinutes,
        );
    }

    #[DataProvider('invalidUpdateInputProvider')]
    public function test_update_data_rejects_invalid_direct_input(array $input, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        UpdateContentCourseData::fromInput($input);
    }

    public static function invalidUpdateInputProvider(): array
    {
        return [
            'blank title' => [['title' => '  '], 'Course title must contain between 1 and 255 characters.'],
            'title array' => [['title' => ['Course']], 'Course title must be a string.'],
            'description array' => [['description' => ['Course']], 'Course description must be a string or null.'],
            'null duration' => [['maxLessonDurationMinutes' => null], 'Course duration must be an integer.'],
            'decimal duration' => [['maxLessonDurationMinutes' => '1.5'], 'Course duration must be an integer.'],
            'low duration' => [['maxLessonDurationMinutes' => 0], 'Course duration must be between 1 and 120 minutes.'],
            'high duration' => [['maxLessonDurationMinutes' => 121], 'Course duration must be between 1 and 120 minutes.'],
        ];
    }

    public function test_update_and_delete_reject_malformed_ids_before_opening_transactions(): void
    {
        $transactionLevel = DB::transactionLevel();

        foreach ([UpdateContentCourseAction::class, DeleteContentCourseAction::class] as $actionClass) {
            try {
                if ($actionClass === UpdateContentCourseAction::class) {
                    app($actionClass)->handle(
                        1,
                        (string) Str::uuid(),
                        'not-a-uuid',
                        UpdateContentCourseData::fromInput([]),
                    );
                } else {
                    app($actionClass)->handle(1, (string) Str::uuid(), 'not-a-uuid');
                }
                $this->fail('Expected malformed Course ID to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Course ID must be a UUID.', $exception->getMessage());
            }

            $this->assertSame($transactionLevel, DB::transactionLevel());
        }
    }

    public function test_update_and_delete_reject_malformed_provenance_before_opening_transactions(): void
    {
        $transactionLevel = DB::transactionLevel();
        $courseId = (string) Str::uuid();

        foreach ([UpdateContentCourseAction::class, DeleteContentCourseAction::class] as $actionClass) {
            try {
                if ($actionClass === UpdateContentCourseAction::class) {
                    app($actionClass)->handle(
                        1,
                        'not-a-uuid',
                        $courseId,
                        UpdateContentCourseData::fromInput([]),
                    );
                } else {
                    app($actionClass)->handle(1, 'not-a-uuid', $courseId);
                }
                $this->fail('Expected malformed Convo Lab user ID to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Convo Lab user ID must be a UUID.', $exception->getMessage());
            }

            $this->assertSame($transactionLevel, DB::transactionLevel());
        }
    }

    public function test_delete_removes_the_course_graph_and_records_a_tombstone(): void
    {
        config()->set('content_courses.audio_disk', 'media');
        Storage::fake('media');
        $user = User::factory()->create();
        $course = $this->courseFor($user);
        $renderingId = (string) Str::uuid();
        $renderingPath = AdminCourseLineAudio::storagePath($course->id, $renderingId);
        AdminCourseLineRendering::query()->forceCreate([
            'id' => $renderingId,
            'course_id' => $course->id,
            'unit_index' => 0,
            'text' => 'Delete this line.',
            'speed' => 1,
            'voice_id' => 'fishaudio:0123456789abcdef0123456789abcdef',
            'audio_url' => AdminCourseLineAudio::audioUrl($course->id, $renderingId),
            'audio_storage_path' => $renderingPath,
            'created_at' => now(),
        ]);
        Storage::disk('media')->put($renderingPath, 'audio');
        $episode = $this->episodeFor($user, $course->convolab_user_id);
        ContentEpisodeCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'convolab_course_id' => $course->id,
            'sort_order' => 0,
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        DB::table('content_course_core_items')->insert([
            'id' => (string) Str::uuid(),
            'course_id' => $course->id,
            'text_l2' => '猫',
            'translation_l1' => 'cat',
            'complexity_score' => 1,
        ]);

        $deleted = app(DeleteContentCourseAction::class)->handle(
            $user->id,
            $course->convolab_user_id,
            $course->id,
        );

        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('content_courses', ['id' => $course->id]);
        $this->assertDatabaseMissing('content_episode_courses', ['convolab_course_id' => $course->id]);
        $this->assertDatabaseMissing('content_course_core_items', ['course_id' => $course->id]);
        $this->assertDatabaseMissing('admin_course_line_renderings', ['id' => $renderingId]);
        Storage::disk('media')->assertMissing($renderingPath);
        $this->assertDatabaseHas('content_course_tombstones', [
            'course_id' => $course->id,
            'user_id' => $user->id,
            'convolab_user_id' => $course->convolab_user_id,
        ]);
        $this->assertDatabaseHas('content_episodes', ['id' => $episode->id]);
    }

    public function test_delete_does_not_trust_a_conflicting_existing_tombstone(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $course = $this->courseFor($user);
        ContentCourseTombstone::query()->forceCreate([
            'course_id' => $course->id,
            'user_id' => $otherUser->id,
            'convolab_user_id' => (string) Str::uuid(),
            'deleted_at' => now(),
        ]);

        $deleted = app(DeleteContentCourseAction::class)->handle(
            $user->id,
            $course->convolab_user_id,
            $course->id,
        );

        $this->assertFalse($deleted);
        $this->assertDatabaseHas('content_courses', ['id' => $course->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function courseFor(User $user, array $overrides = []): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Course',
            'description' => 'Description.',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'en-US-Neural2-J',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            ...$overrides,
        ]);
    }

    private function episodeFor(User $user, string $convoLabUserId): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Episode',
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'auto_generate_audio' => true,
            'status' => 'draft',
            'is_sample_content' => false,
        ]);
    }
}
