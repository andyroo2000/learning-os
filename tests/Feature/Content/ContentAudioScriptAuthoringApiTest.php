<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Services\ContentOpenAiClient;
use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\Content\BuildsContentAudioScripts;
use Tests\TestCase;

class ContentAudioScriptAuthoringApiTest extends TestCase
{
    use BuildsContentAudioScripts;
    use RefreshDatabase;

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
        $media = $this->media($user, ['source_kind' => 'upload']);
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
                            'kana' => 'えきにいきます。',
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
        Storage::fake('media');
        config()->set('content_audio.disk', 'media');
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user, [
            'script' => ['generation_metadata' => ['segmentCount' => 9]],
        ]);
        $oldSegment = $this->segment($script, ['image_status' => 'ready']);
        $renderPath = ContentAudioScriptRenderAudio::storagePath($episode->id, 1, '0.85');
        $this->render($script, [
            'speed' => '0.85',
            'audio_storage_path' => $renderPath,
        ]);
        Storage::disk('media')->put($renderPath, 'render');
        $this->authenticateWrite($user);

        $this->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/convolab/scripts/{$episode->id}/segments", [
                'title' => '  '.str_repeat('題', ContentAudioScriptInput::MAX_TITLE_CHARACTERS).'  ',
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
            ->assertJsonPath('generationMetadataJson', null)
            ->assertJsonPath('segments.0.text', '新しい文です。')
            ->assertJsonPath('segments.0.reading', '新[あたら]しい文[ぶん]です。')
            ->assertJsonPath('segments.0.translation', 'This is a new sentence.')
            ->assertJsonPath('segments.0.imagePrompt', 'A new page.')
            ->assertJsonPath('renders', []);

        $this->assertSame(ContentAudioScriptInput::MAX_TITLE_CHARACTERS, mb_strlen($episode->fresh()->title));
        $this->assertDatabaseMissing('content_audio_script_segments', ['id' => $oldSegment->id]);
        $this->assertDatabaseCount('content_audio_script_renders', 0);
        Storage::disk('media')->assertMissing($renderPath);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
    }

    public function test_segment_update_recovers_a_stale_claim_and_cleans_only_orphaned_generated_media(): void
    {
        Storage::fake('media');
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

        $generated = $this->media($user);
        $shared = $this->media($user, [
            'source_filename' => 'shared.webp',
            'normalized_filename' => 'shared.webp',
            'storage_path' => 'study-media/user/shared.webp',
            'public_url' => '/uploads/study-media/user/shared.webp',
        ]);
        $uploaded = $this->media($user, [
            'source_kind' => 'upload',
            'source_filename' => 'uploaded.webp',
            'normalized_filename' => 'uploaded.webp',
            'storage_path' => 'study-media/user/uploaded.webp',
            'public_url' => '/uploads/study-media/user/uploaded.webp',
        ]);
        $this->segment($script, ['sort_order' => 0, 'image_media_id' => $generated->id]);
        $this->segment($script, ['sort_order' => 1, 'image_media_id' => $uploaded->id]);
        $this->segment($script, ['sort_order' => 2, 'image_media_id' => $shared->id]);
        [, $otherScript] = $this->script($user);
        $this->segment($otherScript, ['image_media_id' => $shared->id]);
        Storage::disk('media')->put($generated->storage_path, 'generated-image');
        Storage::disk('media')->put($shared->storage_path, 'shared-image');
        Storage::disk('media')->put($uploaded->storage_path, 'uploaded-image');
        $this->authenticateWrite($user);

        $this->patchJson("/api/convolab/scripts/{$episode->id}/segments", [
            'segments' => [$this->segmentPayload()],
        ])->assertOk()
            ->assertJsonPath('status', 'annotated')
            ->assertJsonPath('generationMetadataJson', null);

        $this->assertDatabaseMissing('content_audio_script_media', ['id' => $generated->id]);
        Storage::disk('media')->assertMissing($generated->storage_path);
        $this->assertDatabaseHas('content_audio_script_media', ['id' => $shared->id]);
        Storage::disk('media')->assertExists($shared->storage_path);
        $this->assertDatabaseHas('content_audio_script_media', ['id' => $uploaded->id]);
        Storage::disk('media')->assertExists($uploaded->storage_path);
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
            [
                'segments' => [$this->segmentPayload()],
                'title' => str_repeat('題', ContentAudioScriptInput::MAX_TITLE_CHARACTERS + 1),
            ],
            ['segments' => 'not-an-array'],
        ] as $payload) {
            $this->patchJson("/api/convolab/scripts/{$episode->id}/segments", $payload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('content_audio_script_segments', 1);
        $this->assertDatabaseHas('content_audio_script_segments', ['id' => $segment->id]);
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
