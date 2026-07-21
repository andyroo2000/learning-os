<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\FailContentAudioScriptGenerationAction;
use App\Domain\Content\Actions\ProcessContentAudioScriptGenerationAction;
use App\Domain\Content\Data\GenerateContentAudioScriptData;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentAudioScriptGeneratedImagePath;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentAudioScriptGeneration;
use App\Models\User;
use App\Support\Audio\AudioTrackAssembler;
use App\Support\Audio\AudioTrackAssemblyResult;
use App\Support\Images\ImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProcessContentAudioScriptGenerationJobTest extends TestCase
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

    public function test_queue_job_normalizes_identity_and_exposes_retry_configuration(): void
    {
        $id = (string) Str::uuid();
        $job = new ProcessContentAudioScriptGeneration('  '.strtoupper($id).'  ');

        $this->assertSame($id, $job->jobId);
        $this->assertSame($id, $job->uniqueId());
        $this->assertSame(ContentAudioScriptJob::JOB_TRIES, $job->tries);
        $this->assertSame(ContentAudioScriptJob::JOB_TIMEOUT_SECONDS, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([ContentAudioScriptJob::JOB_BACKOFF_SECONDS], $job->backoff());
        $this->assertGreaterThan(ContentAudioScriptJob::JOB_TIMEOUT_SECONDS, ContentAudioScriptJob::ACTIVE_STALE_AFTER_SECONDS);
        $this->assertLessThan(
            ContentAudioScriptJob::JOB_TIMEOUT_SECONDS + ContentAudioScriptJob::JOB_BACKOFF_SECONDS,
            ContentAudioScriptJob::ACTIVE_STALE_AFTER_SECONDS,
        );

        $this->expectException(InvalidArgumentException::class);
        new ProcessContentAudioScriptGeneration('bad-id');
    }

    public function test_generation_data_rejects_invalid_direct_caller_input(): void
    {
        try {
            GenerateContentAudioScriptData::images(['force' => 'true']);
            $this->fail('A direct caller must provide a real boolean.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Script image force must be boolean.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        GenerateContentAudioScriptData::render(['unexpected' => true]);
    }

    public function test_render_process_persists_all_speeds_and_cleans_replaced_files_once(): void
    {
        [$episode, $script, $job] = $this->pendingJob('render');
        $this->segment($script);
        $this->segment($script, [
            'sort_order' => 1,
            'text' => '帰ります。',
            'metadata' => ['japanese' => ['kanji' => '帰ります。']],
        ]);
        $oldPaths = [];
        foreach (ContentAudioScriptRenderAudio::SPEEDS as $speed) {
            $oldPath = ContentAudioScriptRenderAudio::storagePath($episode->id, 1, $speed['speed']);
            $oldPaths[] = $oldPath;
            Storage::disk('media')->put($oldPath, 'old');
            ContentAudioScriptRender::query()->forceCreate([
                'id' => (string) Str::uuid(), 'script_id' => $script->id,
                'speed' => $speed['speed'], 'numeric_speed' => $speed['numericSpeed'],
                'status' => 'ready', 'audio_url' => '/old.mp3', 'audio_storage_path' => $oldPath,
            ]);
        }

        $assembler = $this->mock(AudioTrackAssembler::class);
        foreach (ContentAudioScriptRenderAudio::SPEEDS as $index => $speed) {
            $path = ContentAudioScriptRenderAudio::storagePath($episode->id, 2, $speed['speed']);
            $assembler->shouldReceive('assemble')
                ->once()
                ->withArgs(function (array $units, string $disk, string $actualPath) use ($path, $speed): bool {
                    $this->assertCount(3, $units);
                    $this->assertSame('えきにいきます。', $units[0]->audioText());
                    $this->assertSame($speed['numericSpeed'], $units[0]->audioSpeed());
                    $this->assertSame(0.35, $units[1]->audioPauseSeconds());
                    $this->assertSame('帰ります。', $units[2]->audioText());
                    $this->assertSame('media', $disk);

                    return $actualPath === $path;
                })
                ->andReturnUsing(function () use ($index, $path): AudioTrackAssemblyResult {
                    Storage::disk('media')->put($path, 'new');

                    return new AudioTrackAssemblyResult(
                        $path, 10 - $index,
                        [['unitIndex' => 0, 'startTime' => 0, 'endTime' => 500]],
                        [],
                    );
                });
        }

        app(ProcessContentAudioScriptGenerationAction::class)->handle(strtoupper($job->id));
        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);

        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $job->fresh()->state);
        $this->assertSame(100, $job->fresh()->progress);
        $this->assertSame(['episodeId' => $episode->id, 'status' => 'ready'], $job->fresh()->result);
        $this->assertSame('ready', $script->fresh()->status);
        $this->assertSame('ready', $episode->fresh()->status);
        $renders = $script->renders()->orderBy('numeric_speed')->get();
        $this->assertCount(3, $renders);
        foreach ($renders as $render) {
            $this->assertSame('ready', $render->status);
            $this->assertSame(
                ContentAudioScriptRenderAudio::audioUrl($episode->id, $render->id),
                $render->audio_url,
            );
            Storage::disk('media')->assertExists($render->audio_storage_path);
        }
        foreach ($oldPaths as $oldPath) {
            Storage::disk('media')->assertMissing($oldPath);
        }
    }

    public function test_partial_render_failure_releases_claim_and_deletes_attempt_files_for_retry(): void
    {
        [$episode, $script, $job] = $this->pendingJob('render');
        $this->segment($script);
        $first = ContentAudioScriptRenderAudio::storagePath($episode->id, 2, '0.75');
        $assembler = $this->mock(AudioTrackAssembler::class);
        $assembler->shouldReceive('assemble')->once()->andReturnUsing(function () use ($first): AudioTrackAssemblyResult {
            Storage::disk('media')->put($first, 'partial');

            return new AudioTrackAssemblyResult($first, 3, [], []);
        });
        $assembler->shouldReceive('assemble')->once()->andThrow(new RuntimeException('TTS temporarily unavailable.'));

        try {
            app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);
            $this->fail('The queue should retry the transient render failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('TTS temporarily unavailable.', $exception->getMessage());
        }

        $this->assertSame(ContentAudioScriptJob::STATE_WAITING, $job->fresh()->state);
        $this->assertSame(0, $job->fresh()->progress);
        $this->assertNull($job->fresh()->started_at);
        Storage::disk('media')->assertMissing($first);
        $this->assertSame('generating', $script->fresh()->status);
    }

    public function test_image_process_replaces_generated_media_and_cleans_the_old_file(): void
    {
        [$episode, $script, $job] = $this->pendingJob('images', ['force' => true]);
        $old = ContentAudioScriptMedia::query()->forceCreate([
            'id' => (string) Str::uuid(), 'user_id' => $job->user_id,
            'source_system' => ContentSourceSystem::LEARNING_OS, 'source_kind' => 'generated',
            'source_filename' => 'old.webp', 'normalized_filename' => 'old.webp',
            'media_kind' => 'image', 'content_type' => 'image/webp',
            'storage_path' => 'study-media/audio-scripts/old.webp', 'public_url' => '/old.webp',
        ]);
        $segment = $this->segment($script, [
            'image_status' => 'ready', 'image_media_id' => $old->id, 'image_generated_at' => now(),
        ]);
        Storage::disk('media')->put($old->storage_path, 'old');
        $this->mock(ImageGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->once()->with('A station.')->andReturn($this->webp('new'));
        });

        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);

        $segment->refresh();
        $job->refresh();
        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $job->state);
        $this->assertSame(['episodeId' => $episode->id, 'imageStatus' => 'ready'], $job->result);
        $this->assertSame('ready', $script->fresh()->image_status);
        $this->assertNotSame($old->id, $segment->image_media_id);
        $media = $segment->imageMedia;
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $media->source_system);
        $this->assertSame('image/webp', $media->content_type);
        $this->assertSame("/api/convolab/scripts/media/{$media->id}", $media->public_url);
        $this->assertTrue(ContentAudioScriptGeneratedImagePath::ownsPath(
            $episode->id,
            $segment->id,
            $media->storage_path,
        ));
        $this->assertStringContainsString($media->id, $media->storage_path);
        Storage::disk('media')->assertExists($media->storage_path);
        Storage::disk('media')->assertMissing($old->storage_path);
        $this->assertDatabaseMissing('content_audio_script_media', ['id' => $old->id]);
    }

    public function test_image_process_completes_with_partial_status_when_one_segment_fails(): void
    {
        [$episode, $script, $job] = $this->pendingJob('images', ['force' => false]);
        $first = $this->segment($script, ['sort_order' => 0, 'image_prompt' => 'First scene.']);
        $second = $this->segment($script, ['sort_order' => 1, 'image_prompt' => 'Second scene.']);
        $this->mock(ImageGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->once()->with('First scene.')->andReturn($this->webp('first'));
            $mock->shouldReceive('generate')->once()->with('Second scene.')->andThrow(new RuntimeException('Provider details.'));
        });

        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);

        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $job->fresh()->state);
        $this->assertSame('partial', $job->fresh()->result['imageStatus']);
        $this->assertSame('partial', $script->fresh()->image_status);
        $this->assertSame('1 script image(s) failed or are missing.', $script->fresh()->image_error_message);
        $this->assertSame('ready', $first->fresh()->image_status);
        $this->assertSame('error', $second->fresh()->image_status);
        $this->assertSame(ProcessContentAudioScriptGenerationAction::IMAGE_FAILURE_MESSAGE, $second->fresh()->image_error_message);
    }

    public function test_image_process_rejects_invalid_provider_bytes_without_persisting_media(): void
    {
        [, $script, $job] = $this->pendingJob('images', ['force' => false]);
        $segment = $this->segment($script);
        $this->mock(ImageGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->once()->andReturn('not-webp');
        });

        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);

        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $job->fresh()->state);
        $this->assertSame('error', $job->fresh()->result['imageStatus']);
        $this->assertSame('error', $segment->fresh()->image_status);
        $this->assertSame(ProcessContentAudioScriptGenerationAction::IMAGE_FAILURE_MESSAGE, $segment->fresh()->image_error_message);
        $this->assertNull($segment->fresh()->image_media_id);
        $this->assertDatabaseCount('content_audio_script_media', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_large_image_jobs_continue_in_bounded_queue_safe_batches(): void
    {
        Queue::fake();
        [, $script, $job] = $this->pendingJob('images', ['force' => true]);
        for ($index = 0; $index <= ProcessContentAudioScriptGenerationAction::IMAGE_BATCH_SIZE; $index++) {
            $this->segment($script, [
                'sort_order' => $index,
                'text' => "文{$index}です。",
                'image_prompt' => "Scene {$index}.",
            ]);
        }
        $this->mock(ImageGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->times(ProcessContentAudioScriptGenerationAction::IMAGE_BATCH_SIZE + 1)
                ->andReturn($this->webp('image'));
        });

        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);

        $job->refresh();
        $this->assertSame(ContentAudioScriptJob::STATE_WAITING, $job->state);
        $this->assertSame(ProcessContentAudioScriptGenerationAction::IMAGE_BATCH_SIZE + 1, $job->result['targetCount']);
        $this->assertSame(
            ProcessContentAudioScriptGenerationAction::IMAGE_BATCH_SIZE,
            $script->segments()->where('image_status', 'ready')->count(),
        );
        $this->assertSame(1, $script->segments()->where('image_status', 'generating')->count());
        Queue::assertPushed(
            ProcessContentAudioScriptGeneration::class,
            fn (ProcessContentAudioScriptGeneration $queued): bool => $queued->jobId === $job->id,
        );

        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);
        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $job->fresh()->state);
        $this->assertSame('ready', $job->fresh()->result['imageStatus']);
        $this->assertSame(41, $script->segments()->where('image_status', 'ready')->count());
    }

    public function test_recent_active_terminal_and_superseded_jobs_do_not_call_providers(): void
    {
        [, $script, $recent] = $this->pendingJob('images', ['force' => false]);
        $this->segment($script);
        $recent->state = ContentAudioScriptJob::STATE_ACTIVE;
        $recent->started_at = now();
        $recent->save();
        $this->mock(ImageGenerator::class)->shouldNotReceive('generate');
        app(ProcessContentAudioScriptGenerationAction::class)->handle($recent->id);
        $this->assertSame(ContentAudioScriptJob::STATE_ACTIVE, $recent->fresh()->state);

        $recent->state = ContentAudioScriptJob::STATE_COMPLETED;
        $recent->save();
        app(ProcessContentAudioScriptGenerationAction::class)->handle($recent->id);
        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $recent->fresh()->state);

        [, $staleScript, $stale] = $this->pendingJob('images', ['force' => false]);
        $this->segment($staleScript);
        $staleScript->image_generation_attempt = 3;
        $staleScript->save();
        app(ProcessContentAudioScriptGenerationAction::class)->handle($stale->id);
        $this->assertSame(ContentAudioScriptJob::STATE_FAILED, $stale->fresh()->state);
    }

    public function test_stale_active_image_job_is_reclaimed_and_completed(): void
    {
        [, $script, $job] = $this->pendingJob('images', ['force' => false]);
        $this->segment($script);
        $job->state = ContentAudioScriptJob::STATE_ACTIVE;
        $job->started_at = now()->subSeconds(ContentAudioScriptJob::ACTIVE_STALE_AFTER_SECONDS + 1);
        $job->save();
        $this->mock(ImageGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->once()->andReturn($this->webp('recovered'));
        });

        app(ProcessContentAudioScriptGenerationAction::class)->handle($job->id);

        $this->assertSame(ContentAudioScriptJob::STATE_COMPLETED, $job->fresh()->state);
        $this->assertSame(100, $job->fresh()->progress);
        $this->assertSame('ready', $job->fresh()->result['imageStatus']);
        $this->assertSame('ready', $script->fresh()->image_status);
    }

    public function test_terminal_failure_updates_only_the_attempt_that_still_owns_the_script(): void
    {
        [$episode, $script, $job] = $this->pendingJob('render');
        $path = ContentAudioScriptRenderAudio::storagePath($episode->id, 2, '0.75');
        Storage::disk('media')->put($path, 'partial');

        $this->assertTrue(app(FailContentAudioScriptGenerationAction::class)->handle($job->id, 'Failed safely'));
        $this->assertSame(ContentAudioScriptJob::STATE_FAILED, $job->fresh()->state);
        $this->assertSame('error', $script->fresh()->status);
        $this->assertSame('error', $episode->fresh()->status);
        Storage::disk('media')->assertMissing($path);

        [$newEpisode, $newScript, $superseded] = $this->pendingJob('render');
        $newScript->render_generation_attempt = 3;
        $newScript->status = 'annotated';
        $newScript->save();
        $this->assertTrue(app(FailContentAudioScriptGenerationAction::class)->handle($superseded->id, 'Old failure'));
        $this->assertSame('annotated', $newScript->fresh()->status);
        $this->assertSame('generating', $newEpisode->fresh()->status);
    }

    public function test_terminal_image_failure_deletes_only_unreferenced_attempt_files(): void
    {
        [$episode, $script, $job] = $this->pendingJob('images', ['force' => true]);
        $segment = $this->segment($script, ['image_status' => 'generating']);
        $referencedId = (string) Str::uuid();
        $referencedPath = ContentAudioScriptGeneratedImagePath::storagePath(
            $episode->id,
            $segment->id,
            2,
            $referencedId,
        );
        $media = ContentAudioScriptMedia::query()->forceCreate([
            'id' => $referencedId, 'user_id' => $job->user_id,
            'source_system' => ContentSourceSystem::LEARNING_OS, 'source_kind' => 'generated',
            'source_filename' => basename($referencedPath), 'normalized_filename' => basename($referencedPath),
            'media_kind' => 'image', 'content_type' => 'image/webp',
            'storage_path' => $referencedPath,
            'public_url' => "/api/convolab/scripts/media/{$referencedId}",
        ]);
        $segment->image_media_id = $media->id;
        $segment->save();
        $orphanPath = ContentAudioScriptGeneratedImagePath::storagePath(
            $episode->id,
            $segment->id,
            2,
            (string) Str::uuid(),
        );
        Storage::disk('media')->put($referencedPath, 'referenced');
        Storage::disk('media')->put($orphanPath, 'orphan');

        $this->assertTrue(app(FailContentAudioScriptGenerationAction::class)->handle(
            $job->id,
            ContentAudioScriptJob::FAILED_MESSAGE,
        ));

        Storage::disk('media')->assertExists($referencedPath);
        Storage::disk('media')->assertMissing($orphanPath);
        $this->assertSame('error', $script->fresh()->image_status);
        $this->assertSame('error', $segment->fresh()->image_status);
    }

    /** @return array{ContentEpisode, ContentAudioScript, ContentAudioScriptGenerationJob} */
    private function pendingJob(string $kind, array $input = []): array
    {
        $user = User::factory()->create();
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId, 'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Script', 'source_text' => '駅に行きます。', 'target_language' => 'ja',
            'native_language' => 'en', 'content_type' => 'script', 'status' => 'generating',
            'is_sample_content' => false, 'auto_generate_audio' => false,
        ]);
        $script = ContentAudioScript::query()->forceCreate([
            'id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'status' => $kind === 'render' ? 'generating' : 'annotated',
            'image_status' => $kind === 'images' ? 'generating' : 'pending',
            'voice_id' => 'ja-JP-Neural2-B', 'voice_provider' => 'google',
            'render_generation_attempt' => $kind === 'render' ? 2 : 0,
            'image_generation_attempt' => $kind === 'images' ? 2 : 0,
        ]);
        $job = ContentAudioScriptGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(), 'script_id' => $script->id, 'episode_id' => $episode->id,
            'user_id' => $user->id, 'convolab_user_id' => $this->convoLabUserId,
            'kind' => $kind, 'attempt' => 2, 'state' => ContentAudioScriptJob::STATE_WAITING,
            'progress' => 0, 'input' => $input,
        ]);

        return [$episode, $script, $job];
    }

    private function segment(ContentAudioScript $script, array $attributes = []): ContentAudioScriptSegment
    {
        return ContentAudioScriptSegment::query()->forceCreate([
            'id' => (string) Str::uuid(), 'script_id' => $script->id, 'sort_order' => 0,
            'text' => '駅に行きます。', 'reading' => null, 'translation' => 'I go to the station.',
            'image_prompt' => 'A station.', 'image_status' => 'pending',
            'metadata' => ['japanese' => ['kana' => 'えきにいきます。']],
            ...$attributes,
        ]);
    }

    private function webp(string $payload): string
    {
        return 'RIFF'.pack('V', strlen($payload) + 4).'WEBP'.$payload;
    }
}
