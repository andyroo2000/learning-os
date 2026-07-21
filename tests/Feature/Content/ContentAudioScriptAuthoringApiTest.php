<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Services\ContentOpenAiClient;
use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ContentAudioScriptAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->convoLabUserId = (string) Str::uuid();
    }

    public function test_routes_require_authentication_and_writes_require_proxy_ability(): void
    {
        $episodeId = (string) Str::uuid();
        $mediaId = (string) Str::uuid();

        $this->assertSame(401, $this->postJson('/api/convolab/scripts', [])->status(), 'create');
        $this->assertSame(401, $this->postJson("/api/convolab/scripts/{$episodeId}/annotate")->status(), 'annotate');
        $this->assertSame(401, $this->patchJson("/api/convolab/scripts/{$episodeId}/segments", [])->status(), 'segments');
        $this->assertSame(401, $this->getJson("/api/convolab/scripts/{$episodeId}/status")->status(), 'status');
        $this->getJson("/api/convolab/scripts/media/{$mediaId}")->assertUnauthorized();

        $user = User::factory()->create();
        config()->set('services.convolab.proxy_user_email', 'another@example.com');
        $token = $user->createToken('mobile', ['content:write'])->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->postJson('/api/convolab/scripts', [
                'sourceText' => '日本語です。',
                'voiceId' => ContentAudioScriptInput::DEFAULT_VOICE_ID,
            ])
            ->assertForbidden();

        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', '')
            ->getJson("/api/convolab/scripts/{$episodeId}/status")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
    }

    public function test_create_normalizes_input_and_returns_the_legacy_episode_shape(): void
    {
        $user = User::factory()->create();
        $this->authenticateWrite($user);

        $response = $this->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/convolab/scripts', [
                'sourceText' => '  日本語の原稿です。  ',
                'voiceId' => '  ja-JP-Neural2-B  ',
                'untrusted' => 'discard me',
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Japanese Script')
            ->assertJsonPath('sourceText', '日本語の原稿です。')
            ->assertJsonPath('targetLanguage', 'ja')
            ->assertJsonPath('nativeLanguage', 'en')
            ->assertJsonPath('contentType', 'script')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('autoGenerateAudio', false)
            ->assertJsonPath('audioScript.status', 'draft')
            ->assertJsonPath('audioScript.imageStatus', 'pending')
            ->assertJsonPath('audioScript.voiceId', 'ja-JP-Neural2-B')
            ->assertJsonPath('audioScript.voiceProvider', 'google')
            ->assertJsonMissingPath('untrusted');

        $episode = ContentEpisode::query()->findOrFail($response->json('id'));
        $this->assertSame($user->id, $episode->user_id);
        $this->assertSame($this->convoLabUserId, $episode->convolab_user_id);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->source_system);
        $this->assertTrue(Str::isUuid($episode->id));
        $this->assertTrue(Str::isUuid($episode->audioScript->id));
    }

    public function test_create_defaults_the_voice_and_rejects_invalid_script_input_without_writes(): void
    {
        $user = User::factory()->create();
        $this->authenticateWrite($user);

        $this->postJson('/api/convolab/scripts', ['sourceText' => 'これは原稿です。'])
            ->assertOk()
            ->assertJsonPath('audioScript.voiceId', ContentAudioScriptInput::DEFAULT_VOICE_ID);

        foreach ([
            ['sourceText' => 'English only.'],
            ['sourceText' => '日本語です。', 'voiceId' => 'ja-JP-Wavenet-C'],
            ['sourceText' => str_repeat('日', ContentAudioScriptInput::MAX_SOURCE_CHARACTERS + 1)],
        ] as $payload) {
            $this->postJson('/api/convolab/scripts', $payload)->assertUnprocessable();
        }

        $this->assertDatabaseCount('content_episodes', 1);
        $this->assertDatabaseCount('content_audio_scripts', 1);
    }

    public function test_annotation_replaces_content_promotes_ownership_and_returns_exact_script_shape(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $media = $this->media($user);
        $segment = $this->segment($script, ['image_media_id' => $media->id]);
        $this->render($script);
        $this->mockAnnotation([
            'title' => 'Train Station',
            'segments' => [
                [
                    'text' => '駅に行きます。',
                    'reading' => '駅[えき]に行[い]きます。',
                    'translation' => 'I am going to the station.',
                    'imagePrompt' => 'A person walking to a train station.',
                ],
            ],
        ]);
        $this->authenticateWrite($user);

        $this->postJson('/api/convolab/scripts/'.strtoupper($episode->id).'/annotate')
            ->assertOk()
            ->assertExactJson([
                'id' => $script->id,
                'episodeId' => $episode->id,
                'status' => 'annotated',
                'imageStatus' => 'pending',
                'imageErrorMessage' => null,
                'voiceId' => 'ja-JP-Neural2-D',
                'voiceProvider' => 'google',
                'generationMetadataJson' => ['segmentCount' => 1],
                'errorMessage' => null,
                'createdAt' => ConvoLabTimestamp::serialize($script->created_at),
                'updatedAt' => ConvoLabTimestamp::serialize($script->fresh()->updated_at),
                'segments' => [[
                    'id' => ContentAudioScriptSegment::query()->sole()->id,
                    'scriptId' => $script->id,
                    'order' => 0,
                    'text' => '駅に行きます。',
                    'reading' => '駅[えき]に行[い]きます。',
                    'translation' => 'I am going to the station.',
                    'imagePrompt' => 'A person walking to a train station.',
                    'imageStatus' => 'pending',
                    'imageErrorMessage' => null,
                    'imageMediaId' => null,
                    'imageGeneratedAt' => null,
                    'metadata' => [
                        'japanese' => [
                            'kanji' => '駅に行きます。',
                            'kana' => '駅[えき]に行[い]きます。',
                            'furigana' => '駅[えき]に行[い]きます。',
                        ],
                    ],
                    'createdAt' => ConvoLabTimestamp::serialize(ContentAudioScriptSegment::query()->sole()->created_at),
                    'updatedAt' => ConvoLabTimestamp::serialize(ContentAudioScriptSegment::query()->sole()->updated_at),
                    'imageMedia' => null,
                ]],
                'renders' => [],
            ]);

        $this->assertDatabaseMissing('content_audio_script_segments', ['id' => $segment->id]);
        $this->assertDatabaseCount('content_audio_script_renders', 0);
        $this->assertSame('Train Station', $episode->fresh()->title);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $media->fresh()->source_system);
    }

    public function test_annotation_rejects_changed_source_and_records_a_bounded_failure(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $segment = $this->segment($script);
        $this->mockAnnotation([
            'title' => 'Changed',
            'segments' => [[
                'text' => '内容を変えました。',
                'reading' => '内容[ないよう]を変[か]えました。',
                'translation' => 'I changed the content.',
                'imagePrompt' => 'A changed page.',
            ]],
        ]);
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/scripts/{$episode->id}/annotate")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI script annotation changed the source text.']);

        $this->assertSame('error', $script->fresh()->status);
        $this->assertSame('error', $episode->fresh()->status);
        $this->assertLessThanOrEqual(2_000, mb_strlen($script->fresh()->error_message));
        $this->assertDatabaseHas('content_audio_script_segments', ['id' => $segment->id]);
    }

    public function test_annotation_provider_failure_is_sanitized_and_does_not_replace_segments(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $segment = $this->segment($script);
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->andThrow(new RuntimeException('OpenAI failed to generate Script annotation content.'));
        });
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/scripts/{$episode->id}/annotate")
            ->assertStatus(502)
            ->assertJsonPath('message', 'OpenAI failed to generate Script annotation content.');

        $this->assertSame('error', $script->fresh()->status);
        $this->assertDatabaseHas('content_audio_script_segments', ['id' => $segment->id]);
    }

    public function test_annotation_and_segment_update_reject_concurrent_generation(): void
    {
        $user = User::factory()->create();
        [$episode] = $this->script($user, ['script' => ['status' => 'generating']]);
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/scripts/{$episode->id}/annotate")
            ->assertConflict()
            ->assertExactJson(['message' => 'Script annotation is already in progress.']);
        $this->patchJson("/api/convolab/scripts/{$episode->id}/segments", [
            'segments' => [$this->segmentPayload()],
        ])->assertConflict()->assertExactJson([
            'message' => 'Script annotation is already in progress.',
        ]);
    }

    public function test_annotation_can_recover_a_stale_generation_claim(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user, [
            'script' => [
                'status' => 'generating',
                'generation_metadata' => ['annotationAttempt' => (string) Str::uuid()],
            ],
        ]);
        DB::table('content_audio_scripts')
            ->where('id', $script->id)
            ->update(['updated_at' => now()->subMinutes(4)]);
        $this->mockAnnotation([
            'title' => 'Train Station',
            'segments' => [[
                'text' => '駅に行きます。',
                'reading' => '駅[えき]に行[い]きます。',
                'translation' => 'I am going to the station.',
                'imagePrompt' => 'A person walking to a train station.',
            ]],
        ]);
        $this->authenticateWrite($user);

        $this->postJson("/api/convolab/scripts/{$episode->id}/annotate")
            ->assertOk()
            ->assertJsonPath('status', 'annotated')
            ->assertJsonPath('generationMetadataJson.segmentCount', 1)
            ->assertJsonMissingPath('generationMetadataJson.annotationAttempt');
    }

    public function test_segment_update_uses_only_validated_fields_and_resets_generated_artifacts(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $oldSegment = $this->segment($script, ['image_status' => 'ready']);
        $this->render($script);
        $this->authenticateWrite($user);

        $this->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/convolab/scripts/{$episode->id}/segments", [
                'title' => '  '.str_repeat('題', 130).'  ',
                'voiceId' => '  ja-JP-Neural2-C  ',
                'segments' => [[
                    'text' => '  新しい文です。  ',
                    'reading' => '  新[あたら]しい文[ぶん]です。  ',
                    'translation' => '  This is a new sentence.  ',
                    'imagePrompt' => '  A new page.  ',
                ]],
                'ignored' => 'discarded',
            ])
            ->assertOk()
            ->assertJsonPath('voiceId', 'ja-JP-Neural2-C')
            ->assertJsonPath('status', 'annotated')
            ->assertJsonPath('imageStatus', 'pending')
            ->assertJsonPath('segments.0.text', '新しい文です。')
            ->assertJsonPath('segments.0.reading', '新[あたら]しい文[ぶん]です。')
            ->assertJsonPath('segments.0.translation', 'This is a new sentence.')
            ->assertJsonPath('segments.0.imagePrompt', 'A new page.')
            ->assertJsonPath('renders', []);

        $this->assertSame(120, mb_strlen($episode->fresh()->title));
        $this->assertDatabaseMissing('content_audio_script_segments', ['id' => $oldSegment->id]);
        $this->assertDatabaseCount('content_audio_script_renders', 0);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
    }

    public function test_segment_update_rejects_unvalidated_nested_fields_and_bad_values_without_writes(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $segment = $this->segment($script);
        $this->authenticateWrite($user);

        foreach ([
            ['segments' => [['text' => 'English', 'translation' => 'English']]],
            ['segments' => [[...$this->segmentPayload(), 'unexpected' => 'value']]],
            ['segments' => [$this->segmentPayload()], 'voiceId' => 'Takumi'],
            ['segments' => 'not-an-array'],
        ] as $payload) {
            $this->patchJson("/api/convolab/scripts/{$episode->id}/segments", $payload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('content_audio_script_segments', 1);
        $this->assertDatabaseHas('content_audio_script_segments', ['id' => $segment->id]);
    }

    public function test_status_is_owner_scoped_and_orders_segments_and_renders(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $second = $this->segment($script, ['sort_order' => 1, 'text' => '二番です。']);
        $first = $this->segment($script, ['sort_order' => 0, 'text' => '一番です。']);
        $this->render($script, ['speed' => 'normal', 'numeric_speed' => 1.0]);
        $slow = $this->render($script, ['speed' => 'slow', 'numeric_speed' => 0.7]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);

        $this->getJson('/api/convolab/scripts/'.strtoupper($episode->id).'/status')
            ->assertOk()
            ->assertJsonPath('segments.0.id', $first->id)
            ->assertJsonPath('segments.1.id', $second->id)
            ->assertJsonPath('renders.0.id', $slow->id);

        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->getJson("/api/convolab/scripts/{$episode->id}/status")
            ->assertNotFound();

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->getJson("/api/convolab/scripts/{$episode->id}/status")
            ->assertNotFound();
    }

    public function test_private_media_is_owner_scoped_path_allowlisted_and_security_hardened(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        [, $script] = $this->script($user);
        $media = $this->media($user);
        $this->segment($script, ['image_media_id' => $media->id]);
        Storage::disk('media')->put($media->storage_path, 'image-bytes');
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);

        $this->get('/api/convolab/scripts/media/'.strtoupper($media->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Content-Disposition', 'inline; filename="scene.webp"')
            ->assertHeader('Cache-Control', 'immutable, max-age=15552000, private')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->get("/api/convolab/scripts/media/{$media->id}")
            ->assertNotFound();

        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
        foreach ([
            ['storage_path' => 'study-media/../secret.webp'],
            ['storage_path' => '/study-media/user/scene.webp'],
            ['storage_path' => 'study-media/user/scene.webp', 'content_type' => 'image/svg+xml'],
            ['storage_path' => 'study-media/user/missing.webp'],
        ] as $attributes) {
            $blocked = $this->media($user, $attributes);
            $this->segment($script, [
                'sort_order' => ContentAudioScriptSegment::query()->where('script_id', $script->id)->count(),
                'image_media_id' => $blocked->id,
            ]);
            $this->get("/api/convolab/scripts/media/{$blocked->id}")->assertNotFound();
        }
    }

    public function test_script_routes_use_the_expected_shared_rate_limit_buckets(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => implode('|', $route->methods()).' '.$route->uri());

        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME,
            $routes->get('POST api/convolab/scripts')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME,
            $routes->get('POST api/convolab/scripts/{episodeId}/annotate')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::UPDATE_NAME,
            $routes->get('PATCH api/convolab/scripts/{episodeId}/segments')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME,
            $routes->get('GET|HEAD api/convolab/scripts/media/{mediaId}')->gatherMiddleware(),
        );

        $request = Request::create('/api/convolab/scripts', 'POST', server: ['REMOTE_ADDR' => '203.0.113.4']);
        $request->headers->set('X-Convo-Lab-User-Id', strtoupper($this->convoLabUserId));
        foreach ([
            [ContentAudioScriptRateLimiter::generation($request), ContentAudioScriptRateLimiter::GENERATION_NAME, 10],
            [ContentAudioScriptRateLimiter::update($request), ContentAudioScriptRateLimiter::UPDATE_NAME, 120],
            [ContentAudioScriptRateLimiter::mediaRead($request), ContentAudioScriptRateLimiter::MEDIA_READ_NAME, 240],
        ] as [$limit, $name, $attempts]) {
            $this->assertSame($attempts, $limit->maxAttempts);
            $this->assertSame("{$name}:user:{$this->convoLabUserId}", $limit->key);
        }

        $fallback = Request::create('/api/convolab/scripts', 'POST', server: ['REMOTE_ADDR' => '203.0.113.4']);
        $fallback->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });
        $this->assertSame(
            ContentAudioScriptRateLimiter::GENERATION_NAME.':user:42',
            ContentAudioScriptRateLimiter::generation($fallback)->key,
        );

        $anonymous = Request::create('/api/convolab/scripts', 'POST');
        $this->assertSame(
            ContentAudioScriptRateLimiter::GENERATION_NAME.':anon:127.0.0.1',
            ContentAudioScriptRateLimiter::generation($anonymous)->key,
        );
    }

    private function authenticateWrite(User $user): void
    {
        config()->set('services.convolab.proxy_user_email', $user->email);
        $token = $user->createToken('convolab-proxy', ['content:write'])->plainTextToken;
        $this->withToken($token);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
    }

    /** @return array{ContentEpisode, ContentAudioScript} */
    private function script(User $user, array $attributes = []): array
    {
        $episodeAttributes = $attributes['episode'] ?? [];
        $scriptAttributes = $attributes['script'] ?? [];
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Japanese Script',
            'source_text' => '駅に行きます。',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'script',
            'status' => 'draft',
            'is_sample_content' => false,
            'auto_generate_audio' => false,
            'audio_speed' => 'medium',
            ...$episodeAttributes,
        ]);
        $script = ContentAudioScript::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'status' => 'draft',
            'image_status' => 'pending',
            'voice_id' => ContentAudioScriptInput::DEFAULT_VOICE_ID,
            'voice_provider' => 'google',
            ...$scriptAttributes,
        ]);

        return [$episode, $script];
    }

    private function segment(ContentAudioScript $script, array $attributes = []): ContentAudioScriptSegment
    {
        return ContentAudioScriptSegment::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'script_id' => $script->id,
            'sort_order' => 0,
            'text' => '駅に行きます。',
            'reading' => '駅[えき]に行[い]きます。',
            'translation' => 'I am going to the station.',
            'image_prompt' => 'A train station.',
            'image_status' => 'pending',
            'metadata' => ['japanese' => ['kanji' => '駅に行きます。']],
            ...$attributes,
        ]);
    }

    private function render(ContentAudioScript $script, array $attributes = []): ContentAudioScriptRender
    {
        return ContentAudioScriptRender::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'script_id' => $script->id,
            'speed' => 'medium',
            'numeric_speed' => 0.85,
            'status' => 'ready',
            'audio_url' => '/audio/script.mp3',
            ...$attributes,
        ]);
    }

    private function media(User $user, array $attributes = []): ContentAudioScriptMedia
    {
        return ContentAudioScriptMedia::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'source_kind' => 'generated',
            'source_system' => ContentSourceSystem::CONVOLAB,
            'source_filename' => 'scene.webp',
            'normalized_filename' => 'scene.webp',
            'media_kind' => 'image',
            'content_type' => 'image/webp',
            'storage_path' => 'study-media/user/scene.webp',
            'public_url' => '/uploads/study-media/user/scene.webp',
            ...$attributes,
        ]);
    }

    private function mockAnnotation(array $payload): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock) use ($payload): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->withArgs(fn (string $system, string $prompt, string $label): bool => str_contains($system, 'learner text')
                    && str_contains($prompt, '駅に行きます。')
                    && $label === 'Script annotation')
                ->andReturn(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });
    }

    /** @return array{text: string, reading: string, translation: string, imagePrompt: string} */
    private function segmentPayload(): array
    {
        return [
            'text' => '新しい文です。',
            'reading' => '新[あたら]しい文[ぶん]です。',
            'translation' => 'This is a new sentence.',
            'imagePrompt' => 'A new page.',
        ];
    }
}
