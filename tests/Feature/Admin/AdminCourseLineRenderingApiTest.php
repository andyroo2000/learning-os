<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Admin\Support\AdminCourseLineAudio;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class AdminCourseLineRenderingApiTest extends TestCase
{
    use RefreshDatabase;

    private const VOICE_ID = 'fishaudio:0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('content_courses.audio_disk', 'media');
        Storage::fake('media');
    }

    public function test_routes_enforce_browser_admin_auth_uuid_constraints_and_limiters(): void
    {
        $courseId = (string) Str::uuid();
        $renderingId = (string) Str::uuid();

        $this->postJson("/api/convolab/admin/courses/{$courseId}/synthesize-line")
            ->assertUnauthorized();
        $token = User::factory()->create()
            ->createToken('mobile', ['admin:write'])
            ->plainTextToken;
        $this->withToken($token)
            ->postJson("/api/convolab/admin/courses/{$courseId}/synthesize-line")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->readRequest()
            ->getJson('/api/convolab/admin/courses/not-a-uuid/line-renderings')
            ->assertNotFound();
        $this->readRequest()
            ->getJson("/api/convolab/admin/courses/{$courseId}/line-renderings/not-a-uuid/audio")
            ->assertNotFound();

        $synthesizeRoute = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/courses/{courseId}/synthesize-line',
        );
        $deleteRoute = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/courses/{courseId}/line-renderings/{renderingId}'
                && in_array('DELETE', $route->methods(), true),
        );
        $this->assertNotNull($synthesizeRoute);
        $this->assertNotNull($deleteRoute);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::COURSE_LINE_SYNTHESIZE,
            $synthesizeRoute->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::COURSE_LINE_DELETE,
            $deleteRoute->gatherMiddleware(),
        );
    }

    public function test_it_synthesizes_persists_and_streams_a_course_line(): void
    {
        Carbon::setTestNow('2026-07-22 17:30:45.123456');
        $course = $this->course();
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with('日本語です。', self::VOICE_ID, 0.85)
                ->andReturn('ID3line-audio');
        });
        $this->withoutMiddleware(TrimStrings::class);

        $response = $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/synthesize-line", [
                'text' => '  日本語です。  ',
                'voiceId' => strtoupper(self::VOICE_ID),
                'speed' => 0.85,
                'unitIndex' => 7,
            ])
            ->assertOk();

        $rendering = AdminCourseLineRendering::query()->sole();
        $audioUrl = AdminCourseLineAudio::audioUrl($course->id, $rendering->id);
        $response->assertExactJson([
            'audioUrl' => $audioUrl,
            'renderingId' => $rendering->id,
        ]);
        $this->assertSame(7, $rendering->unit_index);
        $this->assertSame('日本語です。', $rendering->text);
        $this->assertSame(0.85, $rendering->speed);
        $this->assertSame(self::VOICE_ID, $rendering->voice_id);
        $this->assertSame($audioUrl, $rendering->audio_url);
        Storage::disk('media')->assertExists($rendering->audio_storage_path);

        $this->app['auth']->forgetGuards();
        $stream = $this->readRequest()
            ->get($audioUrl)
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg');
        $this->assertSame('ID3line-audio', $stream->streamedContent());
    }

    public function test_synthesis_validates_before_provider_spend_and_hides_provider_failures(): void
    {
        $course = $this->course();
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/synthesize-line", [
                'text' => '',
                'voiceId' => 'fishaudio:not-a-reference',
                'speed' => 4,
                'unitIndex' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text', 'voiceId', 'speed', 'unitIndex']);
        $this->assertDatabaseCount('admin_course_line_renderings', 0);

        $this->app->forgetInstance(AudioSpeechGenerator::class);
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AudioSpeechGenerationException::failed(
                    'Fish Audio',
                    new \RuntimeException('provider credential leaked'),
                ));
        });
        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/synthesize-line", [
                'text' => 'Text',
                'voiceId' => self::VOICE_ID,
                'unitIndex' => 0,
            ])
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Line synthesis is temporarily unavailable']);
        $this->assertDatabaseCount('admin_course_line_renderings', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_missing_course_is_rejected_before_provider_spend(): void
    {
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/courses/'.Str::uuid().'/synthesize-line', [
                'text' => 'Text',
                'voiceId' => self::VOICE_ID,
                'unitIndex' => 0,
            ])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);
    }

    public function test_it_lists_renderings_in_the_legacy_order_and_preserves_imported_urls(): void
    {
        $course = $this->course();
        $old = $this->rendering($course, [
            'unit_index' => 1,
            'audio_url' => 'https://storage.example.test/legacy.mp3',
            'audio_storage_path' => null,
            'created_at' => Carbon::parse('2026-07-20 10:00:00')->setMicrosecond(500000),
        ]);
        $newer = $this->rendering($course, [
            'unit_index' => 1,
            'created_at' => Carbon::parse('2026-07-21 10:00:00')->setMicrosecond(500000),
        ]);
        $firstUnit = $this->rendering($course, [
            'unit_index' => 0,
            'created_at' => Carbon::parse('2026-07-22 10:00:00')->setMicrosecond(500000),
        ]);

        $response = $this->readRequest()
            ->getJson("/api/convolab/admin/courses/{$course->id}/line-renderings")
            ->assertOk();

        $this->assertSame(
            [$firstUnit->id, $newer->id, $old->id],
            array_column($response->json('renderings'), 'id'),
        );
        $response->assertJsonPath('renderings.2.audioUrl', 'https://storage.example.test/legacy.mp3');
        $response->assertJsonPath('renderings.2.courseId', $course->id);
        $response->assertJsonPath('renderings.2.createdAt', '2026-07-20T10:00:00.000Z');
    }

    public function test_list_requires_an_existing_course(): void
    {
        $this->readRequest()
            ->getJson('/api/convolab/admin/courses/'.Str::uuid().'/line-renderings')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);
    }

    public function test_delete_hides_cross_course_ids_and_removes_owned_audio(): void
    {
        $course = $this->course();
        $otherCourse = $this->course();
        $rendering = $this->rendering($course);
        Storage::disk('media')->put($rendering->audio_storage_path, 'ID3audio');

        $this->writeRequest()
            ->deleteJson(
                "/api/convolab/admin/courses/{$otherCourse->id}/line-renderings/{$rendering->id}",
            )
            ->assertNotFound()
            ->assertExactJson(['message' => 'Rendering not found']);
        $this->assertDatabaseHas('admin_course_line_renderings', ['id' => $rendering->id]);

        $this->writeRequest()
            ->deleteJson(
                "/api/convolab/admin/courses/{$course->id}/line-renderings/".strtoupper($rendering->id),
            )
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->assertDatabaseMissing('admin_course_line_renderings', ['id' => $rendering->id]);
        Storage::disk('media')->assertMissing($rendering->audio_storage_path);
    }

    public function test_audio_stream_hides_cross_course_missing_and_legacy_objects(): void
    {
        $course = $this->course();
        $otherCourse = $this->course();
        $rendering = $this->rendering($course);
        $legacy = $this->rendering($course, [
            'audio_url' => 'https://storage.example.test/legacy.mp3',
            'audio_storage_path' => null,
        ]);

        $this->readRequest()
            ->get(AdminCourseLineAudio::audioUrl($otherCourse->id, $rendering->id))
            ->assertNotFound();
        $this->readRequest()
            ->get(AdminCourseLineAudio::audioUrl($course->id, $rendering->id))
            ->assertNotFound();
        $this->readRequest()
            ->get(AdminCourseLineAudio::audioUrl($course->id, $legacy->id))
            ->assertNotFound();
    }

    private function readRequest(): static
    {
        return $this->asConvoLabAdminBrowser();
    }

    private function writeRequest(): static
    {
        return $this->asConvoLabAdminBrowser();
    }

    private function course(): ContentCourse
    {
        $user = User::factory()->create();

        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Line rendering course',
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

    /** @param array<string, mixed> $overrides */
    private function rendering(ContentCourse $course, array $overrides = []): AdminCourseLineRendering
    {
        $id = (string) Str::uuid();

        return AdminCourseLineRendering::query()->forceCreate(array_merge([
            'id' => $id,
            'course_id' => $course->id,
            'unit_index' => 1,
            'text' => 'Line',
            'speed' => 1,
            'voice_id' => self::VOICE_ID,
            'audio_url' => AdminCourseLineAudio::audioUrl($course->id, $id),
            'audio_storage_path' => AdminCourseLineAudio::storagePath($course->id, $id),
            'created_at' => now(),
        ], $overrides));
    }
}
