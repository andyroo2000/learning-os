<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\GenerateAdminCourseScriptAction;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AdminCourseScriptApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.convolab.proxy_user_email' => 'proxy@example.com',
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.content_model' => 'course-test',
            'services.openai.content_reasoning_effort' => 'low',
        ]);
    }

    public function test_route_enforces_write_scope_actor_uuid_and_operation_limiter(): void
    {
        $courseId = (string) Str::uuid();

        $this->withToken($this->proxyToken(['admin:read']))
            ->postJson("/api/convolab/admin/courses/{$courseId}/generate-script")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($this->proxyToken(['admin:write']))
            ->postJson("/api/convolab/admin/courses/{$courseId}/generate-script")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');
        $this->app['auth']->forgetGuards();

        $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson('/api/convolab/admin/courses/not-a-uuid/generate-script')
            ->assertNotFound();

        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/courses/{courseId}/generate-script',
        );
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::COURSE_SCRIPT_GENERATE,
            $route->gatherMiddleware(),
        );
    }

    public function test_it_generates_and_atomically_persists_script_and_core_items(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'script_json' => $this->exchangePipeline(),
            'script_units_json' => [['type' => 'pause', 'seconds' => 9]],
            'approx_duration_seconds' => 9,
            'audio_url' => '/old.mp3',
            'timing_data' => [['start' => 0]],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $episode = $this->episode($user, ['title' => 'Ordering coffee']);
        $this->link($course, $episode, 1);
        $this->oldCoreItem($course);
        Http::fake(['openai.test/v1/responses' => Http::response([
            'output_text' => json_encode(['scriptUnits' => $this->providerUnits()], JSON_THROW_ON_ERROR),
        ])]);

        $response = $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-script")
            ->assertOk()
            ->assertJsonPath('vocabularyItemCount', 2)
            ->assertJsonCount(4, 'scriptUnits');

        $course->refresh();
        $this->assertSame('script', $course->script_json['_pipelineStage']);
        $this->assertEquals($this->exchangePipeline()['_exchanges'], $course->script_json['_exchanges']);
        $this->assertSame($response->json('scriptUnits'), $course->script_json['_scriptUnits']);
        $this->assertSame($response->json('scriptUnits'), $course->script_units_json);
        $this->assertSame($response->json('estimatedDurationSeconds'), $course->approx_duration_seconds);
        $this->assertNull($course->audio_url);
        $this->assertNull($course->timing_data);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
        $this->assertDatabaseCount('content_course_core_items', 2);
        $this->assertDatabaseHas('content_course_core_items', [
            'course_id' => $course->id,
            'text_l2' => '猫',
            'reading_l2' => 'ねこ',
            'translation_l1' => 'cat',
            'complexity_score' => 0,
            'source_episode_id' => $episode->id,
        ]);
        $this->assertDatabaseMissing('content_course_core_items', ['text_l2' => 'old']);
        Http::assertSent(function (Request $request): bool {
            $prompt = $request->data()['input'][1]['content'][0]['text'] ?? '';

            return $request->url() === 'https://openai.test/v1/responses'
                && str_contains($prompt, 'Ordering coffee')
                && str_contains($prompt, '猫です。')
                && str_contains($prompt, 'maximumDurationSeconds');
        });
    }

    public function test_it_uses_course_title_and_nullable_core_source_without_an_episode(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'title' => 'Fallback title',
            'script_json' => $this->exchangePipeline(),
        ]);
        Http::fake(function (Request $request) {
            $this->assertStringContainsString(
                'Fallback title',
                $request->data()['input'][1]['content'][0]['text'],
            );

            return Http::response([
                'output_text' => json_encode(['scriptUnits' => $this->providerUnits()], JSON_THROW_ON_ERROR),
            ]);
        });

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-script")
            ->assertOk();

        $this->assertNull($course->coreItems()->firstOrFail()->source_episode_id);
    }

    public function test_missing_or_invalid_exchange_state_returns_compatible_error_without_provider_call(): void
    {
        $user = User::factory()->create();
        Http::fake();

        foreach ([
            null,
            ['_pipelineStage' => 'script', '_exchanges' => []],
            ['_pipelineStage' => 'exchanges', '_exchanges' => []],
            ['_pipelineStage' => 'exchanges', '_exchanges' => [['bad' => true]]],
        ] as $pipeline) {
            $course = $this->course($user, ['script_json' => $pipeline]);

            $this->writeRequest()
                ->postJson("/api/convolab/admin/courses/{$course->id}/generate-script")
                ->assertBadRequest()
                ->assertExactJson(['message' => 'No dialogue exchanges found. Generate dialogue first.']);
        }

        Http::assertNothingSent();
    }

    public function test_invalid_provider_output_is_a_502_and_preserves_existing_state(): void
    {
        $user = User::factory()->create();
        $pipeline = $this->exchangePipeline();
        $course = $this->course($user, [
            'script_json' => $pipeline,
            'audio_url' => '/old.mp3',
        ]);
        $this->oldCoreItem($course);
        Http::fake(['openai.test/v1/responses' => Http::response([
            'output_text' => '{"wrong":[]}',
        ])]);

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-script")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Script provider returned an invalid response']);

        $course->refresh();
        $this->assertSame($pipeline, $course->script_json);
        $this->assertSame('/old.mp3', $course->audio_url);
        $this->assertSame('old', $course->coreItems()->sole()->text_l2);
    }

    public function test_provider_cannot_select_unknown_voices_or_exceed_duration(): void
    {
        $user = User::factory()->create();

        foreach ([
            [[
                'type' => 'L2', 'text' => '猫', 'reading' => 'ねこ', 'translation' => 'cat',
                'voiceId' => 'unknown', 'speed' => 1,
            ]],
            [['type' => 'pause', 'seconds' => 60], ['type' => 'pause', 'seconds' => 60]],
        ] as $units) {
            $course = $this->course($user, [
                'script_json' => $this->exchangePipeline(),
                'max_lesson_duration_minutes' => 1,
            ]);
            Http::fake(['openai.test/v1/responses' => Http::response([
                'output_text' => json_encode(['scriptUnits' => $units], JSON_THROW_ON_ERROR),
            ])]);

            $this->writeRequest()
                ->postJson("/api/convolab/admin/courses/{$course->id}/generate-script")
                ->assertStatus(502);
            $this->assertSame('exchanges', $course->fresh()->script_json['_pipelineStage']);
        }
    }

    public function test_concurrent_change_rejects_stale_provider_result_and_preserves_newer_state(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['script_json' => $this->exchangePipeline()]);
        $this->link($course, $this->episode($user), 1);
        Http::fake(function () use ($course) {
            ContentCourse::query()->whereKey($course->id)->update([
                'title' => 'Changed concurrently',
                'updated_at' => now()->addSecond(),
            ]);

            return Http::response([
                'output_text' => json_encode(['scriptUnits' => $this->providerUnits()], JSON_THROW_ON_ERROR),
            ]);
        });

        $this->writeRequest()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-script")
            ->assertConflict()
            ->assertExactJson(['message' => 'Course changed while script was being generated']);

        $this->assertSame('Changed concurrently', $course->fresh()->title);
        $this->assertSame('exchanges', $course->fresh()->script_json['_pipelineStage']);
        $this->assertDatabaseCount('content_course_core_items', 0);
    }

    public function test_missing_courses_and_malformed_direct_ids_do_not_call_provider(): void
    {
        Http::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(GenerateAdminCourseScriptAction::class)->handle('bad-id');
            $this->fail('Expected malformed course ID to fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->writeRequest()
            ->postJson('/api/convolab/admin/courses/'.Str::uuid().'/generate-script')
            ->assertNotFound();
        Http::assertNothingSent();
    }

    /** @return list<array<string, mixed>> */
    private function providerUnits(): array
    {
        return [
            ['type' => 'marker', 'label' => 'Lesson Start'],
            ['type' => 'narration_L1', 'text' => 'Welcome.', 'voiceId' => ContentCourseDefaults::NARRATOR_VOICE_EN],
            ['type' => 'pause', 'seconds' => 1.5],
            [
                'type' => 'L2', 'text' => '猫です。', 'reading' => '猫[ねこ]です。',
                'translation' => 'It is a cat.', 'voiceId' => 'fishaudio:speaker-1', 'speed' => 1.0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function exchangePipeline(): array
    {
        return [
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [[
                'order' => 0,
                'speakerName' => 'Aiko',
                'relationshipName' => 'Your friend',
                'speakerVoiceId' => 'fishaudio:speaker-1',
                'textL2' => '猫です。',
                'readingL2' => '猫[ねこ]です。',
                'translationL1' => 'It is a cat.',
                'vocabularyItems' => [
                    ['textL2' => '猫', 'readingL2' => 'ねこ', 'translationL1' => 'cat', 'jlptLevel' => 'N5'],
                    ['textL2' => 'です', 'translationL1' => 'is'],
                ],
            ]],
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
            'description' => 'Description',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => true,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 15,
            'l1_voice_id' => ContentCourseDefaults::NARRATOR_VOICE_EN,
            'l1_voice_provider' => 'fishaudio',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'speaker1_voice_id' => 'fishaudio:speaker-1',
            'speaker1_voice_provider' => 'fishaudio',
            'speaker2_voice_id' => 'fishaudio:speaker-2',
            'speaker2_voice_provider' => 'fishaudio',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function episode(User $user, array $overrides = []): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Episode',
            'source_text' => 'Source',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'auto_generate_audio' => false,
            'status' => 'draft',
            'is_sample_content' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function link(ContentCourse $course, ContentEpisode $episode, int $order): void
    {
        ContentEpisodeCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'convolab_course_id' => $course->id,
            'sort_order' => $order,
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
    }

    private function oldCoreItem(ContentCourse $course): void
    {
        ContentCourseCoreItem::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'course_id' => $course->id,
            'text_l2' => 'old',
            'reading_l2' => null,
            'translation_l1' => 'old',
            'complexity_score' => 0,
            'source_episode_id' => null,
            'source_sentence_id' => null,
            'source_unit_index' => null,
            'components' => null,
        ]);
    }
}
