<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseAudio;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DownloadContentCourseAudioApiTest extends TestCase
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

    public function test_course_audio_requires_authentication_and_a_valid_effective_user(): void
    {
        $courseId = (string) Str::uuid();
        $this->getJson('/api/convolab/courses/'.$courseId.'/audio')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/convolab/courses/'.$courseId.'/audio')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
    }

    public function test_it_streams_current_learning_owned_audio_with_case_insensitive_ids(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $path = ContentCourseAudio::storagePath($course->id, 4);
        $course->forceFill(['audio_storage_path' => $path])->save();
        Storage::disk('course-audio-test')->put($path, 'ID3course-audio');
        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Convo-Lab-User-Id', strtoupper($this->convoLabUserId))
            ->get('/api/convolab/courses/'.strtoupper($course->id).'/audio');

        $response->assertOk()->assertHeader('content-type', 'audio/mpeg');
        $this->assertSame('ID3course-audio', $response->streamedContent());
    }

    public function test_missing_files_and_cross_owner_audio_are_hidden(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $course->forceFill([
            'audio_storage_path' => ContentCourseAudio::storagePath($course->id, 1),
        ])->save();

        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->get('/api/convolab/courses/'.$course->id.'/audio')
            ->assertNotFound();

        Storage::disk('course-audio-test')->put($course->audio_storage_path, 'ID3audio');
        Sanctum::actingAs(User::factory()->create());
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->get('/api/convolab/courses/'.$course->id.'/audio')
            ->assertNotFound();

        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->get('/api/convolab/courses/'.$course->id.'/audio')
            ->assertNotFound();
    }

    public function test_it_never_streams_a_path_outside_course_owned_storage(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $course->forceFill(['audio_storage_path' => 'private/other-user.mp3'])->save();
        Storage::disk('course-audio-test')->put('private/other-user.mp3', 'secret');
        Sanctum::actingAs($user);

        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->get('/api/convolab/courses/'.$course->id.'/audio')
            ->assertNotFound();
    }

    private function course(User $user): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Audio Course',
            'status' => 'ready',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'fishaudio:ac934b39586e475b83f3277cd97b5cd4',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
        ]);
    }
}
