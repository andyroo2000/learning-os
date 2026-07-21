<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ProcessContentImageGenerationAction;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Services\ContentOpenAiClient;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentImageGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProcessContentImageGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_job_normalizes_identity_and_exposes_retry_configuration(): void
    {
        $id = (string) Str::uuid();
        $job = new ProcessContentImageGeneration(' '.strtoupper($id).' ');

        $this->assertSame($id, $job->jobId);
        $this->assertSame($id, $job->uniqueId());
        $this->assertSame(ContentImageGeneration::JOB_TRIES, $job->tries);
        $this->assertSame(ContentImageGeneration::JOB_TIMEOUT_SECONDS, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([ContentImageGeneration::JOB_BACKOFF_SECONDS], $job->backoff());
        $this->assertGreaterThan(ContentImageGeneration::JOB_TIMEOUT_SECONDS, ContentImageGeneration::ACTIVE_STALE_AFTER_SECONDS);
        $this->assertLessThan(
            ContentImageGeneration::JOB_TIMEOUT_SECONDS + ContentImageGeneration::JOB_BACKOFF_SECONDS,
            ContentImageGeneration::ACTIVE_STALE_AFTER_SECONDS,
        );

        $this->expectException(InvalidArgumentException::class);
        new ProcessContentImageGeneration('bad-id');
    }

    public function test_process_plans_ordered_sentence_ranges_and_persists_compatibility_results_once(): void
    {
        [$episode, , $sentences, $job] = $this->pendingJob(2);
        $client = $this->mock(ContentOpenAiClient::class);
        $client->shouldReceive('generateText')
            ->twice()
            ->withArgs(function (string $system, string $prompt, string $label): bool {
                $this->assertStringContainsString('No text, words, letters', $prompt);
                $this->assertStringContainsString('Target language: ja', $prompt);

                return $system !== '' && $label === 'Image prompt';
            })
            ->andReturn('A station farewell scene', 'Friends board the train');

        app(ProcessContentImageGenerationAction::class)->handle(strtoupper($job->id));
        app(ProcessContentImageGenerationAction::class)->handle($job->id);

        $job->refresh();
        $images = $episode->images()->orderBy('sort_order')->get();
        $this->assertSame(ContentImageGeneration::STATE_COMPLETED, $job->state);
        $this->assertSame(100, $job->progress);
        $this->assertCount(2, $images);
        $this->assertCount(2, $job->result);
        $this->assertSame($sentences[0]->id, $images[0]->sentence_start_id);
        $this->assertSame($sentences[1]->id, $images[0]->sentence_end_id);
        $this->assertSame($sentences[2]->id, $images[1]->sentence_start_id);
        $this->assertSame($images[0]->id, $job->result[0]['id']);
        $this->assertSame($episode->id, $job->result[0]['episodeId']);
        $this->assertSame(0, $job->result[0]['order']);
        $this->assertArrayHasKey('createdAt', $job->result[0]);
    }

    public function test_empty_dialogue_completes_without_calling_the_provider(): void
    {
        [, $dialogue, $sentences, $job] = $this->pendingJob(3);
        foreach ($sentences as $sentence) {
            $sentence->delete();
        }
        $this->mock(ContentOpenAiClient::class)->shouldNotReceive('generateText');

        app(ProcessContentImageGenerationAction::class)->handle($job->id);

        $this->assertSame(ContentImageGeneration::STATE_COMPLETED, $job->fresh()->state);
        $this->assertSame([], $job->fresh()->result);
        $this->assertSame(0, $dialogue->episode->images()->count());
    }

    public function test_provider_failure_releases_the_claim_without_persisting_partial_images(): void
    {
        [$episode, , , $job] = $this->pendingJob(2);
        $client = $this->mock(ContentOpenAiClient::class);
        $client->shouldReceive('generateText')->once()->andReturn('First scene');
        $client->shouldReceive('generateText')->once()->andThrow(new RuntimeException('Provider secret.'));

        try {
            app(ProcessContentImageGenerationAction::class)->handle($job->id);
            $this->fail('The queue should retry a temporary provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider secret.', $exception->getMessage());
        }

        $job->refresh();
        $this->assertSame(ContentImageGeneration::STATE_WAITING, $job->state);
        $this->assertSame(0, $job->progress);
        $this->assertNull($job->started_at);
        $this->assertSame(0, $episode->images()->count());
    }

    public function test_recent_active_and_terminal_jobs_are_no_ops_while_stale_jobs_recover(): void
    {
        [, , , $recent] = $this->pendingJob(1);
        $recent->state = ContentImageGeneration::STATE_ACTIVE;
        $recent->started_at = now();
        $recent->save();
        $this->mock(ContentOpenAiClient::class)->shouldNotReceive('generateText');
        app(ProcessContentImageGenerationAction::class)->handle($recent->id);
        $this->assertSame(ContentImageGeneration::STATE_ACTIVE, $recent->fresh()->state);

        $recent->state = ContentImageGeneration::STATE_COMPLETED;
        $recent->save();
        app(ProcessContentImageGenerationAction::class)->handle($recent->id);
        $this->assertSame(ContentImageGeneration::STATE_COMPLETED, $recent->fresh()->state);

        [, , , $stale] = $this->pendingJob(1);
        $stale->state = ContentImageGeneration::STATE_ACTIVE;
        $stale->started_at = now()->subSeconds(ContentImageGeneration::ACTIVE_STALE_AFTER_SECONDS + 1);
        $stale->save();
        $this->forgetMock(ContentOpenAiClient::class);
        $this->mock(ContentOpenAiClient::class)->shouldReceive('generateText')->once()->andReturn('Recovered scene');
        app(ProcessContentImageGenerationAction::class)->handle($stale->id);
        $this->assertSame(ContentImageGeneration::STATE_COMPLETED, $stale->fresh()->state);
    }

    public function test_lost_ownership_after_planning_fails_without_persisting_images(): void
    {
        [$episode, , , $job] = $this->pendingJob(1);
        $this->mock(ContentOpenAiClient::class)
            ->shouldReceive('generateText')
            ->once()
            ->andReturnUsing(function () use ($episode): string {
                $episode->convolab_user_id = (string) Str::uuid();
                $episode->save();

                return 'Orphaned scene';
            });

        app(ProcessContentImageGenerationAction::class)->handle($job->id);

        $job->refresh();
        $this->assertSame(ContentImageGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentImageGeneration::FAILED_MESSAGE, $job->error_message);
        $this->assertSame(0, $episode->images()->count());
    }

    public function test_final_failure_is_generic_and_idempotent(): void
    {
        [, , , $job] = $this->pendingJob(1);
        $queueJob = new ProcessContentImageGeneration($job->id);

        $queueJob->failed(new RuntimeException('Provider secret.'));
        $firstFinishedAt = $job->fresh()->finished_at;
        $queueJob->failed(new RuntimeException('Another secret.'));

        $job->refresh();
        $this->assertSame(ContentImageGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentImageGeneration::FAILED_MESSAGE, $job->error_message);
        $this->assertTrue($firstFinishedAt->equalTo($job->finished_at));
    }

    /** @return array{ContentEpisode, ContentDialogue, list<ContentSentence>, ContentImageGenerationJob} */
    private function pendingJob(int $imageCount): array
    {
        $user = User::factory()->create();
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Train trip',
            'source_text' => 'Friends meet at a station and take a train.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'ready',
            'is_sample_content' => false,
        ]);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
        ]);
        $speaker = ContentSpeaker::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $dialogue->id,
            'name' => 'Takumi',
            'voice_id' => 'ja-JP-Neural2-B',
            'voice_provider' => 'google',
            'proficiency' => 'N4',
            'tone' => 'casual',
        ]);
        $sentences = [];
        foreach (['駅で会いましょう。', '切符を買いました。', '電車に乗ります。'] as $order => $text) {
            $sentences[] = ContentSentence::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'dialogue_id' => $dialogue->id,
                'speaker_id' => $speaker->id,
                'sort_order' => $order,
                'text' => $text,
                'translation' => 'Sentence '.($order + 1),
                'metadata' => [],
            ]);
        }
        $job = ContentImageGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'dialogue_id' => $dialogue->id,
            'user_id' => $user->id,
            'convolab_user_id' => $episode->convolab_user_id,
            'state' => ContentImageGeneration::STATE_WAITING,
            'progress' => 0,
            'image_count' => $imageCount,
        ]);

        return [$episode, $dialogue, $sentences, $job];
    }
}
