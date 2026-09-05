<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentCourseWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_writes_require_a_first_party_browser_session(): void
    {
        $courseId = (string) Str::uuid();
        $this->postJson('/api/convolab/courses')->assertUnauthorized();
        $this->patchJson('/api/convolab/courses/'.$courseId)->assertUnauthorized();
        $this->deleteJson('/api/convolab/courses/'.$courseId)->assertUnauthorized();

        $user = User::factory()->create();
        $ordinaryToken = $user->createToken('mobile', ['content:write'])->plainTextToken;

        $this->withToken($ordinaryToken)
            ->postJson('/api/convolab/courses', $this->inlinePayload())
            ->assertForbidden();
        $this->withToken($ordinaryToken)
            ->patchJson('/api/convolab/courses/'.$courseId, ['title' => 'Updated'])
            ->assertForbidden();
        $this->withToken($ordinaryToken)
            ->deleteJson('/api/convolab/courses/'.$courseId)
            ->assertForbidden();

        $this->assertDatabaseCount('content_courses', 0);
    }

    public function test_browser_session_creates_a_course_from_owned_episodes_in_requested_order(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $imported = $this->episodeFor($user, $sourceUserId, [
            'title' => 'Imported Episode',
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $learningOwned = $this->episodeFor($user, $sourceUserId, [
            'title' => 'Learning Episode',
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);

        $response = $this->asConvoLabBrowser($user, convoLabUserId: strtoupper($sourceUserId))
            ->postJson('/api/convolab/courses', [
                ...$this->basePayload(),
                'description' => 'A focused course.',
                'episodeIds' => [strtoupper($learningOwned->id), $imported->id],
                'maxLessonDurationMinutes' => 20,
                'l1VoiceId' => 'narrator-voice',
                'jlptLevel' => 'N3',
                'speaker1Gender' => 'female',
                'speaker2Gender' => 'male',
                'speaker1VoiceId' => 'speaker-one',
                'speaker2VoiceId' => 'fishaudio:speaker-two',
            ])
            ->assertOk()
            ->assertJsonPath('userId', strtolower($sourceUserId))
            ->assertJsonPath('title', 'New Course')
            ->assertJsonPath('description', 'A focused course.')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('isSampleContent', false)
            ->assertJsonPath('isTestCourse', false)
            ->assertJsonPath('maxLessonDurationMinutes', 20)
            ->assertJsonPath('l1VoiceId', 'narrator-voice')
            ->assertJsonPath('jlptLevel', 'N3')
            ->assertJsonPath('speaker1Gender', 'female')
            ->assertJsonPath('speaker2Gender', 'male')
            ->assertJsonPath('speaker1VoiceProvider', 'google')
            ->assertJsonPath('speaker2VoiceProvider', 'fishaudio')
            ->assertJsonMissingPath('courseEpisodes');

        $courseId = $response->json('id');
        $this->assertIsString($courseId);
        $this->assertTrue(Str::isUuid($courseId));
        $this->assertDatabaseHas('content_courses', [
            'id' => $courseId,
            'user_id' => $user->id,
            'convolab_user_id' => strtolower($sourceUserId),
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'description' => 'A focused course.',
            'max_lesson_duration_minutes' => 20,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'convolab_course_id' => $courseId,
            'episode_id' => $learningOwned->id,
            'sort_order' => 0,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'convolab_course_id' => $courseId,
            'episode_id' => $imported->id,
            'sort_order' => 1,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $imported->fresh()->source_system);
    }

    public function test_inline_source_text_creates_the_episode_and_course_atomically_with_legacy_defaults(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();

        $response = $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', $this->inlinePayload())
            ->assertOk()
            ->assertJsonPath('description', 'Interactive JA audio course with spaced repetition and anticipation drills.')
            ->assertJsonPath('maxLessonDurationMinutes', 30)
            ->assertJsonPath('l1VoiceId', 'fishaudio:ac934b39586e475b83f3277cd97b5cd4')
            ->assertJsonPath('l1VoiceProvider', 'fishaudio')
            ->assertJsonPath('jlptLevel', null)
            ->assertJsonPath('speaker1Gender', 'male')
            ->assertJsonPath('speaker2Gender', 'female')
            ->assertJsonPath('speaker1VoiceProvider', 'google')
            ->assertJsonPath('speaker2VoiceProvider', 'google');

        $courseId = $response->json('id');
        $episode = ContentEpisode::query()->sole();
        $this->assertSame('New Course', $episode->title);
        $this->assertSame('A new inline dialogue.', $episode->source_text);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->source_system);
        $this->assertSame('medium', $episode->audio_speed);
        $this->assertTrue($episode->auto_generate_audio);
        $this->assertDatabaseHas('content_episode_courses', [
            'convolab_course_id' => $courseId,
            'episode_id' => $episode->id,
            'sort_order' => 0,
        ]);
    }

    public function test_client_uuid_inline_course_replay_skips_all_creation_side_effects(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.example/v1');
        Http::fake([
            'https://openai.example/v1/responses' => Http::response([
                'output_text' => 'Generated once.',
            ]),
        ]);
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $courseId = (string) Str::uuid();
        $payload = [...$this->inlinePayload(), 'id' => strtoupper($courseId)];

        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', $payload)
            ->assertOk()
            ->assertJsonPath('id', $courseId)
            ->assertJsonPath('description', 'Generated once.')
            ->assertJsonPath('existing', false);

        $episodeId = ContentEpisode::query()->sole()->id;
        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', [...$this->inlinePayload(), 'id' => $courseId])
            ->assertOk()
            ->assertJsonPath('id', $courseId)
            ->assertJsonPath('description', 'Generated once.')
            ->assertJsonPath('existing', true);

        $this->assertDatabaseCount('content_courses', 1);
        $this->assertDatabaseCount('content_episodes', 1);
        $this->assertDatabaseHas('content_episode_courses', [
            'convolab_course_id' => $courseId,
            'episode_id' => $episodeId,
        ]);
        $this->assertSame(64, strlen(ContentCourse::query()->findOrFail($courseId)->creation_fingerprint));
        Http::assertSentCount(1);
    }

    public function test_client_uuid_course_preserves_episode_order_and_conflicts_when_order_changes(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $first = $this->episodeFor($user, $sourceUserId);
        $second = $this->episodeFor($user, $sourceUserId);
        $courseId = (string) Str::uuid();
        $payload = [
            ...$this->basePayload(),
            'id' => $courseId,
            'description' => 'No provider.',
            'episodeIds' => [$first->id, $second->id],
        ];

        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', $payload)
            ->assertOk()
            ->assertJsonPath('existing', false);
        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', $payload)
            ->assertOk()
            ->assertJsonPath('existing', true);
        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', [...$payload, 'episodeIds' => [$second->id, $first->id]])
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->assertSame(
            [$first->id, $second->id],
            ContentCourse::query()->findOrFail($courseId)->courseEpisodes()->orderBy('sort_order')->pluck('episode_id')->all(),
        );
        $this->assertDatabaseCount('content_episode_courses', 2);
    }

    public function test_client_uuid_course_hides_cross_owner_collision_and_cannot_resurrect_tombstone(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $courseId = (string) Str::uuid();
        $payload = [...$this->inlinePayload(), 'id' => $courseId, 'description' => 'No provider.'];

        $this->asConvoLabBrowser($owner, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', $payload)
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($other, convoLabUserId: (string) Str::uuid())
            ->postJson('/api/convolab/courses', $payload)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);

        $deletedId = (string) Str::uuid();
        ContentCourseTombstone::query()->forceCreate([
            'course_id' => $deletedId,
            'user_id' => $owner->id,
            'convolab_user_id' => $sourceUserId,
            'deleted_at' => now(),
        ]);
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($owner, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', [...$payload, 'id' => $deletedId])
            ->assertGone()
            ->assertJsonPath('code', 'content_gone');

        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser(User::factory()->create(), convoLabUserId: (string) Str::uuid())
            ->postJson('/api/convolab/courses', [...$payload, 'id' => $deletedId])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);

        $this->assertDatabaseMissing('content_courses', ['id' => $deletedId]);
    }

    public function test_missing_description_uses_the_legacy_ai_generation_when_available(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.example/v1');
        config()->set('services.openai.content_model', 'content-test-model');
        config()->set('services.openai.content_reasoning_effort', 'low');
        Http::fake([
            'https://openai.example/v1/responses' => Http::response([
                'output_text' => '  A generated course description.  ',
            ]),
        ]);
        $user = User::factory()->create();

        $this->asConvoLabBrowser($user)
            ->postJson('/api/convolab/courses', $this->inlinePayload())
            ->assertOk()
            ->assertJsonPath('description', 'A generated course description.');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://openai.example/v1/responses'
            && data_get($request->data(), 'model') === 'content-test-model'
            && data_get($request->data(), 'reasoning.effort') === 'low'
            && str_contains((string) data_get($request->data(), 'input.1.content.0.text'), 'New Course'));
    }

    public function test_description_provider_result_does_not_overwrite_a_concurrent_explicit_update(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.example/v1');
        Http::fake(function () {
            $this->travel(1)->second();
            try {
                ContentCourse::query()->sole()->forceFill([
                    'description' => 'Explicit concurrent update.',
                    'description_generation_token' => null,
                ])->save();
            } finally {
                $this->travelBack();
            }

            return Http::response(['output_text' => 'Stale generated description.']);
        });
        $user = User::factory()->create();

        $response = $this->asConvoLabBrowser($user)
            ->postJson('/api/convolab/courses', [...$this->inlinePayload(), 'id' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('description', 'Explicit concurrent update.');

        $this->assertDatabaseHas('content_courses', [
            'id' => $response->json('id'),
            'description' => 'Explicit concurrent update.',
        ]);
    }

    public function test_course_create_rejects_a_malformed_client_uuid_without_writes(): void
    {
        $this->asConvoLabBrowser(User::factory()->create())
            ->postJson('/api/convolab/courses', [...$this->inlinePayload(), 'id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id']);

        $this->assertDatabaseCount('content_courses', 0);
        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_description_provider_failure_keeps_the_created_course_and_fallback(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.example/v1');
        Http::fake([
            'https://openai.example/v1/responses' => Http::response(['error' => []], 503),
        ]);
        $user = User::factory()->create();

        $response = $this->asConvoLabBrowser($user)
            ->postJson('/api/convolab/courses', $this->inlinePayload())
            ->assertOk()
            ->assertJsonPath('description', 'Interactive JA audio course with spaced repetition and anticipation drills.');

        $this->assertDatabaseHas('content_courses', [
            'id' => $response->json('id'),
            'description' => 'Interactive JA audio course with spaced repetition and anticipation drills.',
        ]);
        $this->assertDatabaseCount('content_episodes', 1);
        $this->assertDatabaseCount('content_episode_courses', 1);
    }

    public function test_inline_source_text_takes_legacy_precedence_over_episode_ids(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $existing = $this->episodeFor($user, $sourceUserId);

        $response = $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', [
                ...$this->inlinePayload(),
                'episodeIds' => [['legacy ignores this malformed value']],
            ])
            ->assertOk();

        $this->assertDatabaseCount('content_episodes', 2);
        $this->assertDatabaseMissing('content_episode_courses', [
            'convolab_course_id' => $response->json('id'),
            'episode_id' => $existing->id,
        ]);
    }

    public function test_missing_or_other_owner_episodes_return_hidden_not_found_without_partial_writes(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $owned = $this->episodeFor($user, $sourceUserId);
        $otherSourceEpisode = $this->episodeFor($user, (string) Str::uuid());

        foreach ([$otherSourceEpisode->id, (string) Str::uuid()] as $unavailableId) {
            $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
                ->postJson('/api/convolab/courses', [
                    ...$this->basePayload(),
                    'episodeIds' => [$owned->id, $unavailableId],
                ])
                ->assertNotFound()
                ->assertExactJson(['message' => 'One or more episodes not found']);
        }

        $this->assertDatabaseCount('content_courses', 0);
        $this->assertDatabaseCount('content_episode_courses', 0);
        $this->assertDatabaseCount('content_episodes', 2);
    }

    public function test_course_create_normalizes_ids_before_rejecting_case_insensitive_duplicates(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $episode = $this->episodeFor($user, $sourceUserId);

        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', [
                ...$this->basePayload(),
                'episodeIds' => [$episode->id, strtoupper($episode->id)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['episodeIds.1']);

        $this->assertDatabaseCount('content_courses', 0);
    }

    public function test_request_owned_normalization_does_not_depend_on_trim_strings_middleware(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $episode = $this->episodeFor($user, $sourceUserId);

        $response = $this->asConvoLabBrowser($user, convoLabUserId: strtoupper($sourceUserId))
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/convolab/courses', [
                ...$this->basePayload(),
                'title' => '  Padded Course  ',
                'description' => '  Padded description.  ',
                'episodeIds' => ['  '.strtoupper($episode->id).'  '],
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Padded Course')
            ->assertJsonPath('description', 'Padded description.');

        $this->assertDatabaseHas('content_episode_courses', [
            'convolab_course_id' => $response->json('id'),
            'episode_id' => $episode->id,
        ]);
    }

    public function test_course_create_validates_the_legacy_input_domain(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();

        $this->asConvoLabBrowser($user, convoLabUserId: $sourceUserId)
            ->postJson('/api/convolab/courses', [
                'convolabUserId' => 'not-a-uuid',
                'title' => ['not a string'],
                'episodeIds' => ['not-a-uuid'],
                'sourceText' => ['not a string'],
                'nativeLanguage' => 'ja',
                'targetLanguage' => 'fr',
                'maxLessonDurationMinutes' => 0,
                'l1VoiceId' => '',
                'jlptLevel' => 'N0',
                'speaker1Gender' => 'unknown',
                'speaker2Gender' => 'unknown',
                'speaker1VoiceId' => ['not a string'],
                'speaker2VoiceId' => ['not a string'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title',
                'episodeIds.0',
                'sourceText',
                'nativeLanguage',
                'targetLanguage',
                'maxLessonDurationMinutes',
                'l1VoiceId',
                'jlptLevel',
                'speaker1Gender',
                'speaker2Gender',
                'speaker1VoiceId',
                'speaker2VoiceId',
            ])
            ->assertJsonMissingValidationErrors(['convolabUserId']);

        $this->assertDatabaseCount('content_courses', 0);
    }

    public function test_unsupported_journey_voices_keep_the_legacy_timepointing_fallback(): void
    {
        $user = User::factory()->create();

        $this->asConvoLabBrowser($user)
            ->postJson('/api/convolab/courses', [
                ...$this->inlinePayload(),
                'l1VoiceId' => 'en-US-Journey-D',
            ])
            ->assertOk()
            ->assertJsonPath('l1VoiceId', 'en-US-Neural2-J')
            ->assertJsonPath('l1VoiceProvider', 'google');
    }

    /** @return array<string, string> */
    private function basePayload(): array
    {
        return [
            'title' => 'New Course',
            'nativeLanguage' => 'en',
            'targetLanguage' => 'ja',
        ];
    }

    /** @return array<string, string> */
    private function inlinePayload(): array
    {
        return [
            ...$this->basePayload(),
            'sourceText' => 'A new inline dialogue.',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function episodeFor(User $user, string $sourceUserId, array $overrides = []): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => strtolower($sourceUserId),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Episode',
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'jlpt_level' => null,
            'auto_generate_audio' => true,
            'status' => 'draft',
            'is_sample_content' => false,
            'audio_speed' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}
