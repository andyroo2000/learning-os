<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentAudioScriptGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ContentAudioScriptGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->convoLabUserId = (string) Str::uuid();
        Storage::fake('media');
        config()->set('content_audio.disk', 'media');
    }

    public function test_generation_job_and_audio_routes_require_authentication(): void
    {
        $episodeId = (string) Str::uuid();
        $renderId = (string) Str::uuid();
        $jobId = (string) Str::uuid();

        $this->postJson("/api/convolab/scripts/{$episodeId}/render")->assertUnauthorized();
        $this->postJson("/api/convolab/scripts/{$episodeId}/images", ['force' => false])->assertUnauthorized();
        $this->getJson("/api/convolab/scripts/job/{$jobId}")->assertUnauthorized();
        $this->getJson("/api/convolab/scripts/{$episodeId}/audio/{$renderId}")->assertUnauthorized();
    }

    public function test_render_and_image_generation_queue_independent_durable_attempts(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $this->segment($script);
        $this->authenticateWrite($user);

        $images = $this->postJson("/api/convolab/scripts/{$episode->id}/images", [
            'force' => false,
            'discarded' => true,
        ])->assertOk()
            ->assertJsonPath('message', 'Script image generation started.');
        $render = $this->postJson("/api/convolab/scripts/{$episode->id}/render")
            ->assertOk()
            ->assertJsonPath('message', 'Script audio rendering started.');

        $imageJob = ContentAudioScriptGenerationJob::query()->findOrFail($images->json('jobId'));
        $renderJob = ContentAudioScriptGenerationJob::query()->findOrFail($render->json('jobId'));
        $this->assertSame(ContentAudioScriptJob::KIND_IMAGES, $imageJob->kind);
        $this->assertSame(['force' => false], $imageJob->input);
        $this->assertSame(ContentAudioScriptJob::KIND_RENDER, $renderJob->kind);
        $this->assertSame([], $renderJob->input);
        $this->assertSame(1, $imageJob->attempt);
        $this->assertSame(1, $renderJob->attempt);
        $this->assertSame(1, $script->fresh()->image_generation_attempt);
        $this->assertSame(1, $script->fresh()->render_generation_attempt);
        $this->assertSame('generating', $script->fresh()->image_status);
        $this->assertSame('generating', $script->fresh()->status);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
        Queue::assertPushed(ProcessContentAudioScriptGeneration::class, 2);
    }

    public function test_generation_deduplicates_identical_jobs_and_rejects_different_image_input(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $this->segment($script);
        $this->authenticateWrite($user);

        $first = $this->postJson("/api/convolab/scripts/{$episode->id}/images", ['force' => false])->assertOk();
        $second = $this->postJson("/api/convolab/scripts/{$episode->id}/images", ['force' => false])
            ->assertOk()
            ->assertJsonPath('existing', true);
        $this->assertSame($first->json('jobId'), $second->json('jobId'));

        $this->postJson("/api/convolab/scripts/{$episode->id}/images", ['force' => true])
            ->assertConflict()
            ->assertExactJson(['message' => 'Different script generation is already in progress.']);

        $render = $this->postJson("/api/convolab/scripts/{$episode->id}/render")->assertOk();
        $renderAgain = $this->postJson("/api/convolab/scripts/{$episode->id}/render")
            ->assertOk()
            ->assertJsonPath('existing', true);
        $this->assertSame($render->json('jobId'), $renderAgain->json('jobId'));
        $this->assertDatabaseCount('content_audio_script_generation_jobs', 2);
        Queue::assertPushed(ProcessContentAudioScriptGeneration::class, 2);
    }

    public function test_generation_validates_force_ownership_segments_and_write_ability(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $this->authenticateWrite($user);

        foreach (['true', 1, null, []] as $force) {
            $this->postJson("/api/convolab/scripts/{$episode->id}/images", ['force' => $force])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['force']);
        }
        $this->postJson("/api/convolab/scripts/{$episode->id}/render")
            ->assertConflict()
            ->assertExactJson(['message' => 'Review script segments before starting generation.']);

        $this->segment($script);
        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson("/api/convolab/scripts/{$episode->id}/render")
            ->assertNotFound();

        $other = User::factory()->create();
        config()->set('services.convolab.proxy_user_email', 'different@example.com');
        $token = $other->createToken('mobile', ['content:write'])->plainTextToken;
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson("/api/convolab/scripts/{$episode->id}/render")
            ->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_active_jobs_block_annotation_and_segment_replacement(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $this->segment($script);
        $this->authenticateWrite($user);
        $this->postJson("/api/convolab/scripts/{$episode->id}/images", ['force' => false])->assertOk();

        $this->postJson("/api/convolab/scripts/{$episode->id}/annotate")
            ->assertConflict()
            ->assertExactJson(['message' => 'Script generation is already in progress.']);
        $this->patchJson("/api/convolab/scripts/{$episode->id}/segments", [
            'segments' => [[
                'text' => '新しい文です。',
                'reading' => null,
                'translation' => 'A new sentence.',
                'imagePrompt' => null,
            ]],
        ])->assertConflict()
            ->assertExactJson(['message' => 'Script generation is already in progress.']);
    }

    public function test_dispatch_failure_terminalizes_the_attempt_without_leaking_details(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $this->segment($script);
        $this->authenticateWrite($user);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis password.'));

        $this->postJson("/api/convolab/scripts/{$episode->id}/render")
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => ContentAudioScriptJob::QUEUE_FAILED_MESSAGE]);

        $job = ContentAudioScriptGenerationJob::query()->sole();
        $this->assertSame(ContentAudioScriptJob::STATE_FAILED, $job->state);
        $this->assertSame(ContentAudioScriptJob::QUEUE_FAILED_MESSAGE, $job->error_message);
        $this->assertSame('error', $script->fresh()->status);
        $this->assertNotNull($job->finished_at);
    }

    public function test_job_polling_is_owner_scoped_and_returns_results_only_when_completed(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user, ['script' => ['render_generation_attempt' => 1]]);
        $job = $this->job($user, $episode, $script, [
            'kind' => 'render', 'state' => 'active', 'progress' => 45,
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);

        $this->getJson('/api/convolab/scripts/job/'.strtoupper($job->id))->assertExactJson([
            'id' => $job->id,
            'state' => 'active',
            'progress' => 45,
            'result' => null,
        ]);
        $job->state = 'completed';
        $job->progress = 100;
        $job->result = ['episodeId' => $episode->id, 'status' => 'ready'];
        $job->save();
        $this->getJson("/api/convolab/scripts/job/{$job->id}")
            ->assertJsonPath('result.status', 'ready');

        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->getJson("/api/convolab/scripts/job/{$job->id}")
            ->assertNotFound();
    }

    public function test_render_audio_stream_is_owner_scoped_and_rejects_unsafe_or_missing_paths(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $render = $this->render($script, 1, '0.85');
        Storage::disk('media')->put($render->audio_storage_path, '0123456789');
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);

        $this->get('/api/convolab/scripts/'.strtoupper($episode->id).'/audio/'.strtoupper($render->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $range = $this->withHeader('Range', 'bytes=2-5')
            ->get("/api/convolab/scripts/{$episode->id}/audio/{$render->id}")
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertSame('2345', $range->streamedContent());
        $this->withHeader('Range', 'bytes=20-30')
            ->get("/api/convolab/scripts/{$episode->id}/audio/{$render->id}")
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withoutHeader('Range');

        $render->audio_storage_path = 'content-audio-scripts/../secret.mp3';
        $render->save();
        $this->get("/api/convolab/scripts/{$episode->id}/audio/{$render->id}")->assertNotFound();
        $render->audio_storage_path = ContentAudioScriptRenderAudio::storagePath($episode->id, 2, '0.85');
        $render->save();
        $this->get("/api/convolab/scripts/{$episode->id}/audio/{$render->id}")->assertNotFound();
    }

    private function authenticateWrite(User $user): void
    {
        config()->set('services.convolab.proxy_user_email', $user->email);
        $this->withToken($user->createToken('convolab-proxy', ['content:write'])->plainTextToken);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
    }

    /** @return array{ContentEpisode, ContentAudioScript} */
    private function script(User $user, array $attributes = []): array
    {
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId, 'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Script', 'source_text' => '駅に行きます。', 'target_language' => 'ja',
            'native_language' => 'en', 'content_type' => 'script', 'status' => 'draft',
            'is_sample_content' => false, 'auto_generate_audio' => false,
            ...($attributes['episode'] ?? []),
        ]);
        $script = ContentAudioScript::query()->forceCreate([
            'id' => (string) Str::uuid(), 'episode_id' => $episode->id, 'status' => 'annotated',
            'image_status' => 'pending', 'voice_id' => 'ja-JP-Neural2-B', 'voice_provider' => 'google',
            ...($attributes['script'] ?? []),
        ]);

        return [$episode, $script];
    }

    private function segment(ContentAudioScript $script): ContentAudioScriptSegment
    {
        return ContentAudioScriptSegment::query()->forceCreate([
            'id' => (string) Str::uuid(), 'script_id' => $script->id, 'sort_order' => 0,
            'text' => '駅に行きます。', 'reading' => '駅[えき]に行[い]きます。',
            'translation' => 'I go to the station.', 'image_prompt' => 'A station.',
            'image_status' => 'pending', 'metadata' => ['japanese' => ['kana' => 'えきにいきます。']],
        ]);
    }

    private function job(
        User $user,
        ContentEpisode $episode,
        ContentAudioScript $script,
        array $attributes,
    ): ContentAudioScriptGenerationJob {
        return ContentAudioScriptGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(), 'script_id' => $script->id, 'episode_id' => $episode->id,
            'user_id' => $user->id, 'convolab_user_id' => $this->convoLabUserId,
            'kind' => 'render', 'attempt' => 1, 'state' => 'waiting', 'progress' => 0, 'input' => [],
            ...$attributes,
        ]);
    }

    private function render(ContentAudioScript $script, int $attempt, string $speed): ContentAudioScriptRender
    {
        return ContentAudioScriptRender::query()->forceCreate([
            'id' => (string) Str::uuid(), 'script_id' => $script->id, 'speed' => $speed,
            'numeric_speed' => (float) $speed, 'status' => 'ready',
            'audio_url' => ContentAudioScriptRenderAudio::audioUrl($script->episode_id, (string) Str::uuid()),
            'audio_storage_path' => ContentAudioScriptRenderAudio::storagePath($script->episode_id, $attempt, $speed),
        ]);
    }
}
