<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\BuildAdminCourseScriptConfigAction;
use App\Domain\Admin\Actions\ShowAdminCoursePipelineAction;
use App\Domain\Admin\Actions\UpdateAdminCoursePipelineAction;
use App\Domain\Admin\Data\UpdateAdminCoursePipelineData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AdminCoursePipelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_a_first_party_admin_session(): void
    {
        $courseId = (string) Str::uuid();

        $this->getJson("/api/convolab/admin/courses/{$courseId}/pipeline-data")
            ->assertUnauthorized();
        $token = User::factory()->create()
            ->createToken('mobile', ['admin:write'])
            ->plainTextToken;
        $this->withToken($token)
            ->getJson("/api/convolab/admin/courses/{$courseId}/pipeline-data")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->putJson("/api/convolab/admin/courses/{$courseId}/pipeline-data", [
                'stage' => 'exchanges',
                'data' => [],
            ])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->putJson("/api/convolab/admin/courses/{$courseId}/pipeline-data", [])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson("/api/convolab/admin/courses/{$courseId}/build-script-config")
            ->assertForbidden();
    }

    public function test_show_preserves_null_keys_and_legacy_pipeline_shapes(): void
    {
        $user = User::factory()->create();
        $this->asConvoLabAdminBrowser();
        $empty = $this->course($user, [
            'status' => 'draft',
            'audio_url' => null,
            'approx_duration_seconds' => null,
        ]);

        $this
            ->getJson("/api/convolab/admin/courses/{$empty->id}/pipeline-data")
            ->assertOk()
            ->assertExactJson([
                'id' => $empty->id,
                'status' => 'draft',
                'stage' => null,
                'exchanges' => null,
                'scriptUnits' => null,
                'audioUrl' => null,
                'approxDurationSeconds' => null,
            ]);

        $exchanges = $this->course($user, [
            'script_json' => ['_pipelineStage' => 'exchanges', '_exchanges' => []],
        ]);
        $this
            ->getJson("/api/convolab/admin/courses/{$exchanges->id}/pipeline-data")
            ->assertOk()
            ->assertJsonPath('stage', 'exchanges')
            ->assertJsonPath('exchanges', [])
            ->assertJsonFragment(['scriptUnits' => null]);

        $script = $this->course($user, [
            'script_json' => [
                '_pipelineStage' => 'script',
                '_exchanges' => [['textL2' => '猫']],
                '_scriptUnits' => [],
            ],
            'script_units_json' => [['type' => 'L2', 'text' => 'fallback']],
            'audio_url' => '/audio/course.mp3',
            'approx_duration_seconds' => 75,
        ]);
        $this
            ->getJson("/api/convolab/admin/courses/{$script->id}/pipeline-data")
            ->assertOk()
            ->assertJsonPath('stage', 'script')
            ->assertJsonPath('exchanges.0.textL2', '猫')
            ->assertJsonPath('scriptUnits', [])
            ->assertJsonPath('audioUrl', '/audio/course.mp3')
            ->assertJsonPath('approxDurationSeconds', 75);
    }

    public function test_show_supports_flat_legacy_units_and_the_canonical_fallback(): void
    {
        $user = User::factory()->create();
        $this->asConvoLabAdminBrowser();
        $flat = $this->course($user, [
            'script_json' => [['type' => 'L2', 'text' => 'legacy']],
        ]);
        $fallback = $this->course($user, [
            'script_json' => ['metadata' => true],
            'script_units_json' => [],
        ]);

        $this
            ->getJson("/api/convolab/admin/courses/{$flat->id}/pipeline-data")
            ->assertOk()
            ->assertJsonPath('stage', 'script')
            ->assertJsonPath('scriptUnits.0.text', 'legacy');
        $this
            ->getJson("/api/convolab/admin/courses/{$fallback->id}/pipeline-data")
            ->assertOk()
            ->assertJsonPath('stage', 'script')
            ->assertJsonPath('scriptUnits', []);
    }

    public function test_update_exchanges_claims_source_ownership_and_invalidates_generated_media(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'script_json' => ['_pipelineStage' => 'script', '_scriptUnits' => [['text' => 'old']]],
            'audio_url' => '/audio/old.mp3',
            'timing_data' => [['start' => 0]],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $data = [['speaker' => 'A', 'textL2' => 'こんにちは']];

        $this->asConvoLabAdminBrowser()
            ->putJson("/api/convolab/admin/courses/{$course->id}/pipeline-data", [
                'stage' => 'exchanges',
                'data' => $data,
            ])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $course->refresh();
        $this->assertSame([
            '_pipelineStage' => 'exchanges',
            '_exchanges' => $data,
        ], $course->script_json);
        $this->assertNull($course->audio_url);
        $this->assertNull($course->timing_data);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
    }

    public function test_update_script_preserves_javascript_truthy_empty_exchanges(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'script_json' => ['_pipelineStage' => 'exchanges', '_exchanges' => []],
            'audio_url' => '/audio/old.mp3',
            'timing_data' => [['start' => 0]],
        ]);
        $units = [['type' => 'L2', 'text' => 'おはよう']];

        $this->asConvoLabAdminBrowser()
            ->putJson("/api/convolab/admin/courses/{$course->id}/pipeline-data", [
                'stage' => 'script',
                'data' => $units,
            ])
            ->assertOk();

        $course->refresh();
        $this->assertSame([
            '_pipelineStage' => 'script',
            '_exchanges' => [],
            '_scriptUnits' => $units,
        ], $course->script_json);
        $this->assertNull($course->audio_url);
        $this->assertNull($course->timing_data);
    }

    public function test_update_request_trims_stage_and_enforces_list_shape_and_complexity(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $request = $this->asConvoLabAdminBrowser()
            ->withoutMiddleware(TrimStrings::class);

        $request->putJson("/api/convolab/admin/courses/{$course->id}/pipeline-data", [
            'stage' => ' script ',
            'data' => [],
        ])->assertOk();

        foreach ([
            [['stage' => 'unknown', 'data' => []], 'stage'],
            [['stage' => 'script'], 'data'],
            [['stage' => 'script', 'data' => ['not' => 'a list']], 'data'],
            [['stage' => 'exchanges', 'data' => array_fill(0, 101, [])], 'data'],
            [['stage' => 'script', 'data' => $this->nestedData(14)], 'data'],
            [['stage' => 'script', 'data' => [str_repeat('x', 10001)]], 'data'],
            [['stage' => 'script', 'data' => [array_fill(0, 10001, null)]], 'data'],
            [['stage' => 'script', 'data' => array_fill(0, 51, str_repeat('界', 10000))], 'data'],
            [['stage' => 'script', 'data' => [[str_repeat('k', 256) => true]]], 'data'],
        ] as [$payload, $field]) {
            $request->putJson("/api/convolab/admin/courses/{$course->id}/pipeline-data", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_pipeline_data_enforces_direct_caller_boundaries(): void
    {
        $this->assertCount(
            100,
            UpdateAdminCoursePipelineData::fromInput('exchanges', array_fill(0, 100, []))->data,
        );
        $this->assertCount(
            1000,
            UpdateAdminCoursePipelineData::fromInput('script', array_fill(0, 1000, []))->data,
        );
        $this->assertSame([], UpdateAdminCoursePipelineData::fromInput(' script ', [])->data);

        foreach ([
            ['bad-stage', []],
            ['script', ['not' => 'a list']],
            ['exchanges', array_fill(0, 101, [])],
            ['script', array_fill(0, 1001, [])],
            ['script', $this->nestedData(14)],
            ['script', [str_repeat('x', 10001)]],
            ['script', [array_fill(0, 10001, null)]],
            ['script', array_fill(0, 51, str_repeat('界', 10000))],
            ['script', [[str_repeat('k', 256) => true]]],
            ['script', [INF]],
            ['script', ["\xB1\x31"]],
        ] as [$stage, $data]) {
            try {
                UpdateAdminCoursePipelineData::fromInput($stage, $data);
                $this->fail('Expected invalid direct pipeline data to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_actions_normalize_ids_before_querying_and_hide_missing_courses(): void
    {
        foreach ([
            [ShowAdminCoursePipelineAction::class, fn ($action) => $action->handle('not-a-uuid')],
            [BuildAdminCourseScriptConfigAction::class, fn ($action) => $action->handle('not-a-uuid')],
            [UpdateAdminCoursePipelineAction::class, fn ($action) => $action->handle(
                'not-a-uuid',
                UpdateAdminCoursePipelineData::fromInput('script', []),
            )],
        ] as [$actionClass, $invoke]) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $invoke(app($actionClass));
                $this->fail('Expected malformed course ID to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        }

        foreach ([ShowAdminCoursePipelineAction::class, BuildAdminCourseScriptConfigAction::class] as $actionClass) {
            try {
                app($actionClass)->handle((string) Str::uuid());
                $this->fail('Expected missing course to be hidden.');
            } catch (AdminMutationException $exception) {
                $this->assertSame('Course not found', $exception->getMessage());
                $this->assertSame(404, $exception->status());
            }
        }

        try {
            app(UpdateAdminCoursePipelineAction::class)->handle(
                (string) Str::uuid(),
                UpdateAdminCoursePipelineData::fromInput('script', []),
            );
            $this->fail('Expected missing course update to be hidden.');
        } catch (AdminMutationException $exception) {
            $this->assertSame('Course not found', $exception->getMessage());
            $this->assertSame(404, $exception->status());
        }
    }

    public function test_build_script_config_uses_the_first_episode_and_course_defaults(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'title' => 'Course fallback',
            'target_language' => 'ja',
            'native_language' => 'en',
            'jlpt_level' => 'N4',
        ]);
        $later = $this->episode($user, ['title' => 'Later episode']);
        $first = $this->episode($user, ['title' => 'First episode']);
        $this->link($course, $later, 2);
        $this->link($course, $first, 1);

        $response = $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/build-script-config")
            ->assertOk();

        $config = $response->json('config');
        $this->assertSame(5, $config['reviewAnticipationSeconds']);
        $this->assertSame(0.85, $config['reviewSlowSpeed']);
        $this->assertSame('{relationshipName} says:', $config['speakerSaysTemplate']);
        $this->assertStringContainsString('First episode', $config['scenarioIntroPrompt']);
        $this->assertStringContainsString('JLPT N4 level', $config['scenarioIntroPrompt']);
        $this->assertStringNotContainsString('Later episode', $config['scenarioIntroPrompt']);
        $this->assertStringContainsString('in JA', $config['progressivePhrasePrompt']);
        $this->assertStringContainsString('"long trip" → "long trip"', $config['progressivePhrasePrompt']);

        $fallback = $this->course($user, [
            'title' => 'Fallback title',
            'jlpt_level' => null,
        ]);
        $fallbackConfig = app(BuildAdminCourseScriptConfigAction::class)->handle($fallback->id);
        $this->assertStringContainsString('Fallback title', $fallbackConfig['scenarioIntroPrompt']);
        $this->assertStringNotContainsString('JLPT', $fallbackConfig['scenarioIntroPrompt']);

        $emptyJlpt = $this->course($user, ['jlpt_level' => '']);
        $emptyJlptConfig = app(BuildAdminCourseScriptConfigAction::class)->handle($emptyJlpt->id);
        $this->assertStringNotContainsString('JLPT', $emptyJlptConfig['scenarioIntroPrompt']);
    }

    public function test_routes_constrain_uuid_ids_and_wire_the_named_update_limiter(): void
    {
        $this->asConvoLabAdminBrowser()
            ->getJson('/api/convolab/admin/courses/not-a-uuid/pipeline-data')
            ->assertNotFound();

        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/courses/{courseId}/pipeline-data'
                && in_array('PUT', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::COURSE_PIPELINE_UPDATE,
            $route->gatherMiddleware(),
        );
    }

    /** @return list<mixed> */
    private function nestedData(int $depth): array
    {
        $value = 'leaf';
        for ($index = 0; $index < $depth; $index++) {
            $value = [$value];
        }

        return $value;
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
            'description' => 'Course description',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
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
}
