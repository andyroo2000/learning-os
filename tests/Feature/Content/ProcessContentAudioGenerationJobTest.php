<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ProcessContentAudioGenerationAction;
use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Domain\Content\Support\ContentEpisodeAudio;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentAudioGeneration;
use App\Models\User;
use App\Support\Audio\AudioTrackAssembler;
use App\Support\Audio\AudioTrackAssemblyResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProcessContentAudioGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config()->set('content_audio.disk', 'media');
    }

    public function test_queue_job_normalizes_identity_and_exposes_retry_configuration(): void
    {
        $id = (string) Str::uuid();
        $job = new ProcessContentAudioGeneration('  '.strtoupper($id).'  ');

        $this->assertSame($id, $job->jobId);
        $this->assertSame($id, $job->uniqueId());
        $this->assertSame(ContentAudioGeneration::JOB_TRIES, $job->tries);
        $this->assertSame(ContentAudioGeneration::JOB_TIMEOUT_SECONDS, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([ContentAudioGeneration::JOB_BACKOFF_SECONDS], $job->backoff());
        $this->assertGreaterThan(
            ContentAudioGeneration::JOB_TIMEOUT_SECONDS,
            ContentAudioGeneration::ACTIVE_STALE_AFTER_SECONDS,
        );
        $this->assertLessThan(
            ContentAudioGeneration::JOB_TIMEOUT_SECONDS + ContentAudioGeneration::JOB_BACKOFF_SECONDS,
            ContentAudioGeneration::ACTIVE_STALE_AFTER_SECONDS,
        );

        $this->expectException(InvalidArgumentException::class);
        new ProcessContentAudioGeneration('bad-id');
    }

    public function test_all_speed_process_persists_tracks_timings_and_result_once_then_cleans_old_files(): void
    {
        [$episode, $dialogue, $sentences, $job] = $this->pendingAttempt();
        $oldPaths = [];
        foreach ([ContentEpisodeAudio::TRACK_SLOW, ContentEpisodeAudio::TRACK_MEDIUM, ContentEpisodeAudio::TRACK_NORMAL] as $track) {
            $oldPath = ContentEpisodeAudio::storagePath($episode->id, 1, $track);
            $oldPaths[$track] = $oldPath;
            Storage::disk('media')->put($oldPath, 'old');
        }
        $episode->audio_storage_path_0_7 = $oldPaths[ContentEpisodeAudio::TRACK_SLOW];
        $episode->audio_storage_path_0_85 = $oldPaths[ContentEpisodeAudio::TRACK_MEDIUM];
        $episode->audio_storage_path_1_0 = $oldPaths[ContentEpisodeAudio::TRACK_NORMAL];
        $episode->save();

        $shared = $this->mock(AudioTrackAssembler::class);
        foreach ([
            [ContentEpisodeAudio::TRACK_SLOW, 0.7, 10, 1100],
            [ContentEpisodeAudio::TRACK_MEDIUM, 0.85, 9, 1000],
            [ContentEpisodeAudio::TRACK_NORMAL, 1.0, 8, 900],
        ] as [$track, $speed, $duration, $secondStart]) {
            $newPath = ContentEpisodeAudio::storagePath($episode->id, 2, $track);
            $shared->shouldReceive('assemble')
                ->once()
                ->withArgs(function (array $units, string $disk, string $path) use ($newPath, $speed): bool {
                    $this->assertCount(3, $units);
                    $this->assertSame($speed, $units[0]->audioSpeed());
                    $this->assertSame(1.0, $units[1]->audioPauseSeconds());
                    $this->assertSame('media', $disk);

                    return $path === $newPath;
                })
                ->andReturnUsing(function () use ($duration, $newPath, $secondStart): AudioTrackAssemblyResult {
                    Storage::disk('media')->put($newPath, 'new');

                    return new AudioTrackAssemblyResult($newPath, $duration, [
                        ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 500],
                        ['unitIndex' => 1, 'startTime' => 500, 'endTime' => $secondStart],
                        ['unitIndex' => 2, 'startTime' => $secondStart, 'endTime' => $secondStart + 600],
                    ], $this->metadata());
                });
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(ProcessContentAudioGenerationAction::class)->handle(strtoupper($job->id));
            $sentenceUpdates = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update "content_sentences"'),
            );
        } finally {
            DB::disableQueryLog();
        }
        app(ProcessContentAudioGenerationAction::class)->handle($job->id);

        $job->refresh();
        $episode->refresh();
        $this->assertSame(ContentAudioGeneration::STATE_COMPLETED, $job->state);
        $this->assertSame(100, $job->progress);
        $this->assertCount(3, $job->result);
        $this->assertSame(['slow', 'medium', 'normal'], array_column($job->result, 'speed'));
        $this->assertSame(10000, $job->result[0]['duration']);
        $this->assertSame(ContentEpisodeAudio::audioUrl($episode->id, '0.7'), $episode->audio_url_0_7);
        $this->assertSame(ContentEpisodeAudio::audioUrl($episode->id, '0.85'), $episode->audio_url_0_85);
        $this->assertSame(ContentEpisodeAudio::audioUrl($episode->id, '1.0'), $episode->audio_url_1_0);
        $this->assertSame(0, $sentences[0]->fresh()->start_time_0_7);
        $this->assertSame(1100, $sentences[1]->fresh()->start_time_0_7);
        $this->assertSame(1600, $sentences[1]->fresh()->end_time_0_85);
        $this->assertCount(2, $sentenceUpdates, 'Each sentence should receive all speed timings in one update.');

        foreach ($oldPaths as $oldPath) {
            Storage::disk('media')->assertMissing($oldPath);
        }
        foreach (['0.7', '0.85', '1.0'] as $track) {
            Storage::disk('media')->assertExists(ContentEpisodeAudio::storagePath($episode->id, 2, $track));
        }
    }

    public function test_single_speed_process_uses_legacy_speed_and_pause_contract(): void
    {
        [$episode, , $sentences, $job] = $this->pendingAttempt([
            'mode' => 'single',
            'speed' => 'very-slow',
            'pauseMode' => true,
        ]);
        $path = ContentEpisodeAudio::storagePath($episode->id, 2, ContentEpisodeAudio::TRACK_DEFAULT);
        $this->mock(AudioTrackAssembler::class)
            ->shouldReceive('assemble')
            ->once()
            ->withArgs(function (array $units) use ($sentences): bool {
                $this->assertSame(0.65, $units[0]->audioSpeed());
                $this->assertSame(1.5, $units[1]->audioPauseSeconds());
                $this->assertSame($sentences[0]->metadata['japanese']['kana'], $units[0]->audioText());

                return true;
            })
            ->andReturn(new AudioTrackAssemblyResult($path, 7, [
                ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 400],
                ['unitIndex' => 1, 'startTime' => 400, 'endTime' => 1900],
                ['unitIndex' => 2, 'startTime' => 1900, 'endTime' => 2400],
            ], $this->metadata()));

        app(ProcessContentAudioGenerationAction::class)->handle($job->id);

        $episode->refresh();
        $job->refresh();
        $this->assertSame('very-slow', $episode->audio_speed);
        $this->assertSame(ContentEpisodeAudio::audioUrl($episode->id, 'default'), $episode->audio_url);
        $this->assertSame($path, $episode->audio_storage_path);
        $this->assertSame(1900, $sentences[1]->fresh()->start_time);
        $this->assertSame(7000, $job->result['duration']);
        $this->assertArrayHasKey($sentences[0]->id, $job->result['sentenceTimings']);
    }

    public function test_partial_assembly_failure_deletes_new_files_and_releases_claim_for_retry(): void
    {
        [$episode, , , $job] = $this->pendingAttempt();
        $firstPath = ContentEpisodeAudio::storagePath($episode->id, 2, ContentEpisodeAudio::TRACK_SLOW);
        $secondPath = ContentEpisodeAudio::storagePath($episode->id, 2, ContentEpisodeAudio::TRACK_MEDIUM);
        $shared = $this->mock(AudioTrackAssembler::class);
        $shared->shouldReceive('assemble')->once()->andReturnUsing(function () use ($firstPath): AudioTrackAssemblyResult {
            Storage::disk('media')->put($firstPath, 'partial');

            return new AudioTrackAssemblyResult($firstPath, 3, [
                ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 500],
                ['unitIndex' => 2, 'startTime' => 1500, 'endTime' => 2000],
            ], $this->metadata());
        });
        $shared->shouldReceive('assemble')->once()->andReturnUsing(function () use ($secondPath): never {
            Storage::disk('media')->put($secondPath, 'incomplete');
            throw new RuntimeException('Temporary TTS failure.');
        });

        try {
            app(ProcessContentAudioGenerationAction::class)->handle($job->id);
            $this->fail('The queue should retry a temporary synthesis failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary TTS failure.', $exception->getMessage());
        }

        $job->refresh();
        $this->assertSame(ContentAudioGeneration::STATE_WAITING, $job->state);
        $this->assertSame(0, $job->progress);
        $this->assertNull($job->started_at);
        Storage::disk('media')->assertMissing($firstPath);
        Storage::disk('media')->assertMissing($secondPath);
        $this->assertNull($episode->fresh()->audio_url_0_7);
    }

    public function test_recent_active_terminal_and_stale_attempts_do_not_synthesize(): void
    {
        [, , , $recent] = $this->pendingAttempt();
        $recent->state = ContentAudioGeneration::STATE_ACTIVE;
        $recent->started_at = now();
        $recent->save();
        $this->mock(AudioTrackAssembler::class)->shouldNotReceive('assemble');

        app(ProcessContentAudioGenerationAction::class)->handle($recent->id);
        $this->assertSame(ContentAudioGeneration::STATE_ACTIVE, $recent->fresh()->state);

        [$staleEpisode, , , $stale] = $this->pendingAttempt([], ['audio_generation_attempt' => 3]);
        app(ProcessContentAudioGenerationAction::class)->handle($stale->id);
        $this->assertSame(ContentAudioGeneration::STATE_FAILED, $stale->fresh()->state);
        $this->assertSame(3, $staleEpisode->fresh()->audio_generation_attempt);

        $recent->state = ContentAudioGeneration::STATE_COMPLETED;
        $recent->save();
        app(ProcessContentAudioGenerationAction::class)->handle($recent->id);
        $this->assertSame(ContentAudioGeneration::STATE_COMPLETED, $recent->fresh()->state);
    }

    public function test_stale_active_claim_recovers_and_lost_ownership_discards_generated_track(): void
    {
        [$episode, , , $job] = $this->pendingAttempt([
            'mode' => 'single', 'speed' => 'normal', 'pauseMode' => false,
        ]);
        $job->state = ContentAudioGeneration::STATE_ACTIVE;
        $job->started_at = now()->subSeconds(
            ContentAudioGeneration::JOB_TIMEOUT_SECONDS + ContentAudioGeneration::JOB_BACKOFF_SECONDS + 1,
        );
        $job->save();
        $path = ContentEpisodeAudio::storagePath($episode->id, 2, 'default');
        $this->mock(AudioTrackAssembler::class)->shouldReceive('assemble')->once()->andReturnUsing(
            function () use ($episode, $path): AudioTrackAssemblyResult {
                Storage::disk('media')->put($path, 'orphan');
                $episode->audio_generation_attempt = 3;
                $episode->save();

                return new AudioTrackAssemblyResult($path, 2, [
                    ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 400],
                    ['unitIndex' => 2, 'startTime' => 1400, 'endTime' => 1800],
                ], $this->metadata());
            },
        );

        app(ProcessContentAudioGenerationAction::class)->handle($job->id);

        $this->assertSame(ContentAudioGeneration::STATE_FAILED, $job->fresh()->state);
        $this->assertSame(ContentAudioGeneration::FAILED_MESSAGE, $job->fresh()->error_message);
        Storage::disk('media')->assertMissing($path);
        $this->assertNull($episode->fresh()->audio_url);
    }

    public function test_final_failure_is_generic_and_idempotent(): void
    {
        [$episode, , , $job] = $this->pendingAttempt();
        $queueJob = new ProcessContentAudioGeneration($job->id);
        foreach (ContentEpisodeAudio::tracks() as $track) {
            Storage::disk('media')->put(
                ContentEpisodeAudio::storagePath($episode->id, 2, $track),
                'uncommitted',
            );
        }

        $queueJob->failed(new RuntimeException('Provider secret.'));
        $firstFinishedAt = $job->fresh()->finished_at;
        $queueJob->failed(new RuntimeException('Second secret.'));

        $job->refresh();
        $this->assertSame(ContentAudioGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentAudioGeneration::FAILED_MESSAGE, $job->error_message);
        $this->assertTrue($firstFinishedAt->equalTo($job->finished_at));
        foreach (ContentEpisodeAudio::tracks() as $track) {
            Storage::disk('media')->assertMissing(ContentEpisodeAudio::storagePath($episode->id, 2, $track));
        }
    }

    /**
     * @param  array<string, mixed>  $inputAttributes
     * @param  array<string, mixed>  $episodeAttributes
     * @return array{ContentEpisode, ContentDialogue, list<ContentSentence>, ContentAudioGenerationJob}
     */
    private function pendingAttempt(array $inputAttributes = [], array $episodeAttributes = []): array
    {
        $user = User::factory()->create();
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Dialogue',
            'source_text' => 'A trip.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'ready',
            'is_sample_content' => false,
            'audio_generation_attempt' => 2,
            ...$episodeAttributes,
        ]);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
        ]);
        $speakers = [];
        foreach (['ja-JP-Neural2-B', 'Takumi'] as $index => $voiceId) {
            $speakers[] = ContentSpeaker::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'dialogue_id' => $dialogue->id,
                'name' => 'Speaker '.($index + 1),
                'voice_id' => $voiceId,
                'voice_provider' => 'google',
                'proficiency' => 'N4',
                'tone' => 'casual',
            ]);
        }
        $sentences = [];
        foreach ([['旅行へ行こう。', 'りょこうへいこう。'], ['いいね。', 'いいね。']] as $index => [$text, $kana]) {
            $sentences[] = ContentSentence::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'dialogue_id' => $dialogue->id,
                'speaker_id' => $speakers[$index]->id,
                'sort_order' => $index,
                'text' => $text,
                'translation' => $index === 0 ? 'Let us travel.' : 'Sounds good.',
                'metadata' => ['japanese' => ['kana' => $kana]],
            ]);
        }
        $input = [
            'episodeId' => $episode->id,
            'dialogueId' => $dialogue->id,
            'mode' => 'all-speeds',
            'speed' => 'normal',
            'pauseMode' => false,
            ...$inputAttributes,
        ];
        $job = ContentAudioGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'dialogue_id' => $dialogue->id,
            'user_id' => $user->id,
            'convolab_user_id' => $episode->convolab_user_id,
            'attempt' => 2,
            'state' => ContentAudioGeneration::STATE_WAITING,
            'progress' => 0,
            'input' => $input,
        ]);

        return [$episode, $dialogue, $sentences, $job];
    }

    /** @return array{unitCount: int, spokenUnitCount: int, pauseUnitCount: int, uniqueSynthesisCount: int, reusedSynthesisCount: int} */
    private function metadata(): array
    {
        return [
            'unitCount' => 3,
            'spokenUnitCount' => 2,
            'pauseUnitCount' => 1,
            'uniqueSynthesisCount' => 2,
            'reusedSynthesisCount' => 0,
        ];
    }
}
