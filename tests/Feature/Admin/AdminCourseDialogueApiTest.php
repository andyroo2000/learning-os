<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\BuildAdminCoursePromptAction;
use App\Domain\Admin\Actions\GenerateAdminCourseDialogueAction;
use App\Domain\Admin\Data\GenerateAdminCourseDialogueData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Support\AdminCoursePromptSeedRepository;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Services\ContentOpenAiClient;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminCourseDialogueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_enforce_browser_admin_auth_and_uuid_constraints(): void
    {
        $courseId = (string) Str::uuid();

        $this->postJson("/api/convolab/admin/courses/{$courseId}/build-prompt")
            ->assertUnauthorized();

        $token = User::factory()->create()
            ->createToken('mobile', ['admin:write'])
            ->plainTextToken;
        $this->withToken($token)
            ->postJson("/api/convolab/admin/courses/{$courseId}/build-prompt")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson("/api/convolab/admin/courses/{$courseId}/generate-dialogue")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->asConvoLabAdminBrowser()
            ->postJson('/api/convolab/admin/courses/not-a-uuid/build-prompt')
            ->assertNotFound();

        $this->asConvoLabAdminBrowser()
            ->postJson('/api/convolab/admin/courses/not-a-uuid/generate-dialogue')
            ->assertNotFound();

        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/courses/{courseId}/generate-dialogue',
        );
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::COURSE_DIALOGUE_GENERATE,
            $route->gatherMiddleware(),
        );
    }

    public function test_build_prompt_uses_first_episode_and_returns_legacy_metadata_shape(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'max_lesson_duration_minutes' => 30,
            'jlpt_level' => 'N4',
        ]);
        $later = $this->episode($user, ['title' => 'Later', 'source_text' => 'Later source']);
        $first = $this->episode($user, ['title' => 'First', 'source_text' => 'First source']);
        $this->link($course, $later, 2);
        $this->link($course, $first, 1);

        $this->mock(AdminCoursePromptSeedRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sampleVocabulary')->once()->with('ja', 'N4', 30)->andReturn([
                ['word' => '猫', 'reading' => 'ねこ', 'translation' => 'cat'],
            ]);
            $mock->shouldReceive('sampleGrammar')->once()->with('ja', 'N4', 5)->andReturn([
                [
                    'pattern' => '〜ながら',
                    'meaning' => 'while',
                    'example' => '歩きながら話す。',
                    'exampleTranslation' => 'Talk while walking.',
                ],
            ]);
        });

        $response = $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/build-prompt")
            ->assertOk()
            ->assertJsonStructure([
                'prompt',
                'metadata' => ['targetExchangeCount', 'vocabularySeeds', 'grammarSeeds'],
            ])
            ->assertJsonPath('metadata.targetExchangeCount', 20);

        $prompt = $response->json('prompt');
        $this->assertStringContainsString('Title: "First"', $prompt);
        $this->assertStringContainsString('Scenario: "First source"', $prompt);
        $this->assertStringNotContainsString('Later source', $prompt);
        $this->assertStringContainsString('Generate 20 dialogue exchanges in JA', $prompt);
        $this->assertStringContainsString('猫 (ねこ) - cat', $prompt);
        $this->assertStringContainsString('〜ながら (while): 歩きながら話す。', $prompt);
        $this->assertStringContainsString('Speaker 1 is male', $prompt);
        $this->assertStringContainsString('Speaker 2 is female', $prompt);
        $this->assertStringContainsString('北[ほっ]海[かい]道[どう]', $prompt);
    }

    public function test_build_prompt_omits_seed_sections_without_a_truthy_level(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['jlpt_level' => '']);
        $episode = $this->episode($user);
        $this->link($course, $episode, 1);
        $this->mock(AdminCoursePromptSeedRepository::class)
            ->shouldNotReceive('sampleVocabulary', 'sampleGrammar');

        $response = $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/build-prompt")
            ->assertOk()
            ->assertJsonPath('metadata.vocabularySeeds', '')
            ->assertJsonPath('metadata.grammarSeeds', '');

        $this->assertStringNotContainsString('JLPT LEVEL CONSTRAINT', $response->json('prompt'));
    }

    public function test_generate_dialogue_uses_custom_prompt_reuses_voices_and_saves_pipeline_state(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, [
            'script_json' => ['old' => true],
            'audio_url' => '/audio/old.mp3',
            'timing_data' => [['start' => 0]],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $episode = $this->episode($user);
        $this->link($course, $episode, 1);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ContentSpeaker::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $dialogue->id,
            'name' => 'Aiko',
            'voice_id' => 'fishaudio:existing-aiko',
            'voice_provider' => 'fishaudio',
            'proficiency' => 'N4',
            'tone' => 'casual',
            'gender' => 'female',
        ]);

        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->withArgs(fn (string $system, string $prompt, string $label): bool => $prompt === 'Use this exact prompt'
                    && $label === 'Admin dialogue'
                    && str_contains($system, 'JSON shape'))
                ->andReturn($this->providerJson());
        });

        $response = $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-dialogue", [
                'customPrompt' => 'Use this exact prompt',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'exchanges')
            ->assertJsonPath('exchanges.0.speakerName', 'aiko')
            ->assertJsonPath('exchanges.0.speakerVoiceId', 'fishaudio:existing-aiko')
            ->assertJsonPath('exchanges.0.vocabularyItems.0.textL2', '猫')
            ->assertJsonPath('exchanges.1.relationshipName', 'Ken')
            ->assertJsonPath('exchanges.1.speakerVoiceId', 'fishaudio:speaker-1');

        $course->refresh();
        $this->assertArrayHasKey('readingL2', $response->json('exchanges.1'));
        $this->assertNull($response->json('exchanges.1.readingL2'));
        $this->assertSame('exchanges', $course->script_json['_pipelineStage']);
        $this->assertSame($response->json('exchanges'), $course->script_json['_exchanges']);
        $this->assertNull($course->audio_url);
        $this->assertNull($course->timing_data);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
    }

    public function test_generate_dialogue_builds_a_fresh_prompt_only_when_custom_prompt_is_empty(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['jlpt_level' => null]);
        $episode = $this->episode($user, ['title' => 'Fresh prompt']);
        $this->link($course, $episode, 1);

        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->withArgs(fn (string $system, string $prompt, string $label): bool => str_contains($prompt, 'Title: "Fresh prompt"')
                    && str_contains($prompt, 'Scenario: "Source"'))
                ->andReturn($this->providerJson());
        });

        $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-dialogue", [
                'customPrompt' => '',
            ])
            ->assertOk();
    }

    public function test_invalid_provider_output_is_a_502_and_does_not_mutate_the_course(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['script_json' => ['old' => true]]);
        $this->link($course, $this->episode($user), 1);
        $this->mock(ContentOpenAiClient::class)
            ->shouldReceive('generateJson')
            ->once()
            ->andReturn('{"wrong":[]}');

        $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-dialogue", [
                'customPrompt' => 'Prompt',
            ])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Dialogue provider returned an invalid response']);

        $this->assertSame(['old' => true], $course->fresh()->script_json);
    }

    public function test_concurrent_course_change_rejects_stale_generation_result(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user, ['script_json' => ['old' => true]]);
        $this->link($course, $this->episode($user), 1);
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock) use ($course): void {
            $mock->shouldReceive('generateJson')->once()->andReturnUsing(function () use ($course): string {
                ContentCourse::query()->whereKey($course->id)->update([
                    'title' => 'Changed concurrently',
                    'updated_at' => now()->addSecond(),
                ]);

                return $this->providerJson();
            });
        });

        $this->asConvoLabAdminBrowser()
            ->postJson("/api/convolab/admin/courses/{$course->id}/generate-dialogue", [
                'customPrompt' => 'Prompt',
            ])
            ->assertConflict()
            ->assertExactJson(['message' => 'Course changed while dialogue was being generated']);

        $this->assertSame(['old' => true], $course->fresh()->script_json);
    }

    public function test_missing_courses_sources_and_malformed_direct_ids_use_compatible_errors(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $data = GenerateAdminCourseDialogueData::fromInput(['customPrompt' => 'Prompt']);

        foreach ([
            [BuildAdminCoursePromptAction::class, fn ($action) => $action->handle('bad-id')],
            [GenerateAdminCourseDialogueAction::class, fn ($action) => $action->handle('bad-id', $data)],
        ] as [$class, $invoke]) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $invoke(app($class));
                $this->fail('Expected malformed course ID to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        }

        foreach ([BuildAdminCoursePromptAction::class, GenerateAdminCourseDialogueAction::class] as $class) {
            try {
                $class === BuildAdminCoursePromptAction::class
                    ? app($class)->handle((string) Str::uuid())
                    : app($class)->handle((string) Str::uuid(), $data);
                $this->fail('Expected missing course to be hidden.');
            } catch (AdminMutationException $exception) {
                $this->assertSame(404, $exception->status());
            }
        }

        foreach ([
            fn () => app(BuildAdminCoursePromptAction::class)->handle($course->id),
            fn () => app(GenerateAdminCourseDialogueAction::class)->handle($course->id, $data),
        ] as $invoke) {
            try {
                $invoke();
                $this->fail('Expected missing source text to be rejected.');
            } catch (AdminMutationException $exception) {
                $this->assertSame('Course has no episode with source text', $exception->getMessage());
                $this->assertSame(400, $exception->status());
            }
        }
    }

    public function test_request_and_direct_data_bound_custom_prompts(): void
    {
        $user = User::factory()->create();
        $course = $this->course($user);
        $this->link($course, $this->episode($user), 1);
        $request = $this->asConvoLabAdminBrowser();

        $request->postJson("/api/convolab/admin/courses/{$course->id}/generate-dialogue", [
            'customPrompt' => ['not a string'],
        ])->assertUnprocessable()->assertJsonValidationErrors('customPrompt');
        $request->postJson("/api/convolab/admin/courses/{$course->id}/generate-dialogue", [
            'customPrompt' => str_repeat('x', 100_001),
        ])->assertUnprocessable()->assertJsonValidationErrors('customPrompt');

        $this->assertNull(GenerateAdminCourseDialogueData::fromInput([])->customPrompt);
        $this->assertSame('  ', GenerateAdminCourseDialogueData::fromInput(['customPrompt' => '  '])->customPrompt);
        $this->assertSame(' Prompt ', GenerateAdminCourseDialogueData::fromInput([
            'customPrompt' => ' Prompt ',
        ])->customPrompt);

        foreach ([['customPrompt' => []], ['customPrompt' => str_repeat('x', 100_001)]] as $input) {
            try {
                GenerateAdminCourseDialogueData::fromInput($input);
                $this->fail('Expected invalid custom prompt to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_seed_repository_uses_full_level_assets_and_bounds_samples(): void
    {
        $repository = new AdminCoursePromptSeedRepository;
        $words = $repository->sampleVocabulary('ja', 'N4', 30);
        $grammar = $repository->sampleGrammar('ja', 'N4', 5);

        $this->assertCount(30, $words);
        $this->assertCount(30, array_unique(array_column($words, 'word')));
        $this->assertCount(5, $grammar);
        $this->assertSame([], $repository->sampleVocabulary('en', 'N4'));
        $this->assertSame([], $repository->sampleGrammar('ja', 'invalid'));
    }

    private function providerJson(): string
    {
        return json_encode([
            'exchanges' => [
                [
                    'order' => 0,
                    'speakerName' => 'aiko',
                    'relationshipName' => 'Your friend',
                    'textL2' => '猫です。',
                    'reading' => '猫[ねこ]です。',
                    'translation' => 'It is a cat.',
                    'vocabulary' => [[
                        'word' => '猫 (neko)',
                        'reading' => 'ねこ',
                        'translation' => 'cat',
                        'jlptLevel' => 'N5',
                    ]],
                ],
                [
                    'order' => 1,
                    'speakerName' => 'Ken',
                    'relationshipName' => '',
                    'textL2' => 'そうです。',
                    'reading' => '',
                    'translation' => 'That is right.',
                    'vocabulary' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
}
