<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ProcessContentDialogueGenerationAction;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentDialogueGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProcessContentDialogueGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_job_normalizes_identity_and_exposes_retry_configuration(): void
    {
        $id = (string) Str::uuid();
        $job = new ProcessContentDialogueGeneration('  '.strtoupper($id).'  ');

        $this->assertSame($id, $job->jobId);
        $this->assertSame($id, $job->uniqueId());
        $this->assertSame(ContentDialogueGeneration::JOB_TRIES, $job->tries);
        $this->assertSame(ContentDialogueGeneration::JOB_TIMEOUT_SECONDS, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([ContentDialogueGeneration::JOB_BACKOFF_SECONDS], $job->backoff());

        $this->expectException(InvalidArgumentException::class);
        new ProcessContentDialogueGeneration('bad-id');
    }

    public function test_process_replaces_the_dialogue_graph_and_completes_the_durable_attempt_once(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            '*' => Http::response(['output_text' => json_encode([
                'title' => 'Travel Plans',
                'sentences' => [
                    [
                        'speaker' => 'Aiko',
                        'text' => '旅行に行こう。',
                        'reading' => '旅行[りょこう]に 行[い]こう。',
                        'translation' => 'Let us take a trip.',
                        'variations' => ['旅に出よう。', '旅行しよう。'],
                    ],
                    [
                        'speaker' => 'Ken',
                        'text' => 'いいですね。',
                        'reading' => 'いいですね。',
                        'translation' => 'That sounds good.',
                        'variations' => ['賛成です。', '楽しそうです。'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)], 200),
        ]);
        [$episode, $job] = $this->pendingAttempt();
        $oldDialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
        ]);
        $oldSpeaker = ContentSpeaker::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $oldDialogue->id,
            'name' => 'Old',
            'voice_id' => 'Takumi',
            'proficiency' => 'N5',
            'tone' => 'neutral',
        ]);
        ContentSentence::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $oldDialogue->id,
            'speaker_id' => $oldSpeaker->id,
            'sort_order' => 0,
            'text' => '古い',
            'translation' => 'Old',
            'metadata' => [],
        ]);

        app(ProcessContentDialogueGenerationAction::class)->handle(strtoupper($job->id));
        app(ProcessContentDialogueGenerationAction::class)->handle($job->id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === config('services.openai.base_url').'/responses'
            && $request['text']['format']['type'] === 'json_object');
        $episode->refresh();
        $job->refresh();
        $this->assertSame('Travel Plans', $episode->title);
        $this->assertSame('ready', $episode->status);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->source_system);
        $this->assertSame(ContentDialogueGeneration::STATE_COMPLETED, $job->state);
        $this->assertSame(100, $job->progress);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->error_message);
        $this->assertDatabaseMissing('content_dialogues', ['id' => $oldDialogue->id]);

        $dialogue = $episode->dialogue()->firstOrFail();
        $speakers = $dialogue->speakers()->orderBy('name')->get();
        $this->assertCount(2, $speakers);
        $this->assertSame(['Aiko', 'Ken'], $speakers->pluck('name')->all());
        $this->assertSame('google', $speakers[0]->voice_provider);
        $this->assertSame('female', $speakers[0]->gender);
        $this->assertSame('/api/avatars/ja-female-casual.jpg', $speakers[0]->avatar_url);
        $this->assertSame('#F97316', $speakers[1]->color);

        $sentences = $dialogue->sentences()->orderBy('sort_order')->get();
        $this->assertCount(2, $sentences);
        $this->assertSame([0, 1], $sentences->pluck('sort_order')->all());
        $this->assertSame($speakers->firstWhere('name', 'Aiko')->id, $sentences[0]->speaker_id);
        $this->assertSame('旅行[りょこう]に 行[い]こう。', $sentences[0]->metadata['japanese']['furigana']);
        $this->assertSame('りょこうに いこう。', $sentences[0]->metadata['japanese']['kana']);
        $this->assertSame(['旅に出よう。', '旅行しよう。'], $sentences[0]->variations);
    }

    public function test_stale_or_terminal_attempts_never_call_the_provider_or_overwrite_episode_state(): void
    {
        Http::fake();
        [$episode, $stale] = $this->pendingAttempt(['dialogue_generation_attempt' => 2]);
        $terminal = $this->generationJob($episode, [
            'attempt' => 2,
            'state' => ContentDialogueGeneration::STATE_COMPLETED,
            'progress' => 100,
        ]);

        app(ProcessContentDialogueGenerationAction::class)->handle($stale->id);
        app(ProcessContentDialogueGenerationAction::class)->handle($terminal->id);

        Http::assertNothingSent();
        $this->assertSame(ContentDialogueGeneration::STATE_FAILED, $stale->fresh()->state);
        $this->assertSame(ContentDialogueGeneration::FAILED_MESSAGE, $stale->fresh()->error_message);
        $this->assertSame(ContentDialogueGeneration::STATE_COMPLETED, $terminal->fresh()->state);
        $this->assertSame('generating', $episode->fresh()->status);
        $this->assertSame(2, $episode->fresh()->dialogue_generation_attempt);
    }

    public function test_final_failure_is_generic_attempt_guarded_and_idempotent(): void
    {
        [$episode, $job] = $this->pendingAttempt();
        $queueJob = new ProcessContentDialogueGeneration($job->id);

        $queueJob->failed(new RuntimeException('Provider secret.'));
        $firstFinishedAt = $job->fresh()->finished_at;
        $queueJob->failed(new RuntimeException('Second failure.'));

        $job->refresh();
        $this->assertSame(ContentDialogueGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentDialogueGeneration::FAILED_MESSAGE, $job->error_message);
        $this->assertNotNull($firstFinishedAt);
        $this->assertTrue($firstFinishedAt->equalTo($job->finished_at));
        $this->assertSame('error', $episode->fresh()->status);

        $episode->refresh();
        $episode->status = 'generating';
        $episode->dialogue_generation_attempt = 2;
        $episode->save();
        (new ProcessContentDialogueGeneration($job->id))->failed(new RuntimeException('Stale.'));
        $this->assertSame('generating', $episode->fresh()->status);
    }

    /** @param array<string, mixed> $episodeAttributes
     * @return array{ContentEpisode, ContentDialogueGenerationJob}
     */
    private function pendingAttempt(array $episodeAttributes = []): array
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $sourceUserId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Draft',
            'source_text' => 'Two friends plan a trip.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'generating',
            'is_sample_content' => false,
            'dialogue_generation_attempt' => 1,
            ...$episodeAttributes,
        ]);

        return [$episode, $this->generationJob($episode)];
    }

    /** @param array<string, mixed> $attributes */
    private function generationJob(ContentEpisode $episode, array $attributes = []): ContentDialogueGenerationJob
    {
        return ContentDialogueGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'user_id' => $episode->user_id,
            'convolab_user_id' => $episode->convolab_user_id,
            'attempt' => 1,
            'state' => ContentDialogueGeneration::STATE_WAITING,
            'progress' => 0,
            'input' => [
                'episodeId' => $episode->id,
                'speakers' => [
                    ['name' => 'Aiko', 'voiceId' => 'ja-JP-Neural2-B', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => '#112233'],
                    ['name' => 'Ken', 'voiceId' => 'Takumi', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
                ],
                'variationCount' => 2,
                'dialogueLength' => 2,
                'jlptLevel' => 'N4',
                'vocabSeedOverride' => null,
                'grammarSeedOverride' => null,
            ],
            ...$attributes,
        ]);
    }
}
