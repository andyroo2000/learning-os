<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Domain\Content\Support\ContentImageRateLimiter;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentImageGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContentImageGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->convoLabUserId = (string) Str::uuid();
    }

    public function test_generation_requires_authentication_and_write_ability(): void
    {
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $payload = $this->payload($episode, $dialogue);

        $this->postJson('/api/convolab/images/generate', $payload)->assertUnauthorized();

        $this->withToken($user->createToken('read-only', ['content:read'])->plainTextToken)
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->postJson('/api/convolab/images/generate', $payload)
            ->assertForbidden();
    }

    public function test_generation_normalizes_ids_uses_defaults_and_dispatches_once(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);

        $response = $this->postJson('/api/convolab/images/generate', [
            'episodeId' => ' '.strtoupper($episode->id).' ',
            'dialogueId' => ' '.strtoupper($dialogue->id).' ',
        ])->assertOk()->assertJsonPath('message', 'Image generation started');

        $job = ContentImageGenerationJob::query()->sole();
        $this->assertSame(3, $job->image_count);
        $this->assertSame($job->id, $response->json('jobId'));
        Queue::assertPushed(
            ProcessContentImageGeneration::class,
            fn (ProcessContentImageGeneration $queued): bool => $queued->jobId === $job->id,
        );

        $this->withoutMiddleware(TrimStrings::class);
        $second = $this->postJson('/api/convolab/images/generate', [
            'episodeId' => ' '.strtoupper($episode->id).' ',
            'dialogueId' => ' '.strtoupper($dialogue->id).' ',
            'imageCount' => '+8',
        ])->assertOk();
        $this->assertSame($job->id, $second->json('jobId'));
        $this->assertDatabaseCount('content_image_generation_jobs', 1);
        Queue::assertPushed(ProcessContentImageGeneration::class, 1);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_generation_rejects_invalid_input_without_side_effects(array $changes, array $errors): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);

        $this->postJson('/api/convolab/images/generate', [
            ...$this->payload($episode, $dialogue),
            ...$changes,
        ])->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('content_image_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'bad episode' => [['episodeId' => 'bad'], ['episodeId']],
            'array dialogue' => [['dialogueId' => ['bad']], ['dialogueId']],
            'zero count' => [['imageCount' => 0], ['imageCount']],
            'excessive count' => [['imageCount' => 11], ['imageCount']],
        ];
    }

    public function test_generation_hides_mismatched_ownership_and_terminalizes_dispatch_failure(): void
    {
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($other)
            ->postJson('/api/convolab/images/generate', $this->payload($episode, $dialogue))
            ->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($user);
        $this->postJson('/api/convolab/images/generate', [
            'episodeId' => $episode->id,
            'dialogueId' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseCount('content_image_generation_jobs', 0);

        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis password.'));
        $this->postJson('/api/convolab/images/generate', $this->payload($episode, $dialogue))
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => ContentImageGeneration::QUEUE_FAILED_MESSAGE]);

        $job = ContentImageGenerationJob::query()->sole();
        $this->assertSame(ContentImageGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentImageGeneration::QUEUE_FAILED_MESSAGE, $job->error_message);
        $this->assertNotNull($job->finished_at);
    }

    public function test_reposting_redispatches_waiting_or_stale_jobs_but_not_a_recent_active_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $job = $this->job($episode, $dialogue, [
            'state' => ContentImageGeneration::STATE_ACTIVE,
            'progress' => 10,
            'claim_token' => (string) Str::uuid(),
            'started_at' => now(),
        ]);
        $this->authenticateWrite($user);

        $this->postJson('/api/convolab/images/generate', $this->payload($episode, $dialogue))
            ->assertOk()
            ->assertJsonPath('jobId', $job->id);
        Queue::assertNothingPushed();

        $job->started_at = now()->subSeconds(ContentImageGeneration::ACTIVE_STALE_AFTER_SECONDS + 1);
        $job->save();
        $this->postJson('/api/convolab/images/generate', $this->payload($episode, $dialogue))
            ->assertOk()
            ->assertJsonPath('jobId', $job->id);
        Queue::assertPushed(
            ProcessContentImageGeneration::class,
            fn (ProcessContentImageGeneration $queued): bool => $queued->jobId === $job->id,
        );
    }

    public function test_polling_is_owner_scoped_and_reveals_only_completed_results(): void
    {
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $job = $this->job($episode, $dialogue, [
            'state' => ContentImageGeneration::STATE_ACTIVE,
            'progress' => 40,
            'result' => [['prompt' => 'must remain hidden']],
        ]);
        $this->authenticateWrite($user);

        $this->getJson('/api/convolab/images/job/'.strtoupper($job->id))->assertExactJson([
            'id' => $job->id,
            'state' => ContentImageGeneration::STATE_ACTIVE,
            'progress' => 40,
            'result' => null,
        ]);
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser(User::factory()->create())
            ->getJson('/api/convolab/images/job/'.$job->id)
            ->assertNotFound();

        $job->state = ContentImageGeneration::STATE_COMPLETED;
        $job->progress = 100;
        $job->result = [['prompt' => 'safe result']];
        $job->save();
        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($user);
        $this->getJson('/api/convolab/images/job/'.$job->id)
            ->assertJsonPath('result.0.prompt', 'safe result');
    }

    public function test_generation_has_a_per_user_rate_limit_and_server_fields_are_guarded(): void
    {
        $request = Request::create('/api/convolab/images/generate', 'POST');
        $request->setUserResolver(
            fn (): User => User::factory()->make([
                'convolab_id' => strtoupper($this->convoLabUserId),
            ]),
        );
        $limit = ContentImageRateLimiter::generation($request);
        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(ContentImageRateLimiter::GENERATION_NAME.':user:'.$this->convoLabUserId, $limit->key);
        $fallback = Request::create('/api/convolab/images/generate', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.8',
        ]);
        $this->assertSame(
            ContentImageRateLimiter::GENERATION_NAME.':anon:192.0.2.8',
            ContentImageRateLimiter::generation($fallback)->key,
        );

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate): bool => $candidate->uri() === 'api/convolab/images/generate');
        $this->assertNotNull($route);
        $this->assertContains('throttle:'.ContentImageRateLimiter::GENERATION_NAME, $route->gatherMiddleware());

        foreach (['id', 'episode_id', 'dialogue_id', 'user_id', 'convolab_user_id', 'state', 'progress', 'image_count', 'claim_token', 'result', 'error_message', 'started_at', 'finished_at'] as $field) {
            try {
                (new ContentImageGenerationJob)->fill([$field => 'untrusted']);
                $this->fail("{$field} should not be mass assignable.");
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function authenticateWrite(User $user): void
    {
        $this->asConvoLabBrowser($user, convoLabUserId: $this->convoLabUserId);
    }

    /** @return array{ContentEpisode, ContentDialogue} */
    private function episodeWithDialogue(User $user): array
    {
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Dialogue',
            'source_text' => 'Two friends plan a trip.',
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

        return [$episode, $dialogue];
    }

    /** @return array{episodeId: string, dialogueId: string} */
    private function payload(ContentEpisode $episode, ContentDialogue $dialogue): array
    {
        return ['episodeId' => $episode->id, 'dialogueId' => $dialogue->id];
    }

    /** @param array<string, mixed> $attributes */
    private function job(ContentEpisode $episode, ContentDialogue $dialogue, array $attributes = []): ContentImageGenerationJob
    {
        return ContentImageGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'dialogue_id' => $dialogue->id,
            'user_id' => $episode->user_id,
            'convolab_user_id' => $episode->convolab_user_id,
            'state' => ContentImageGeneration::STATE_WAITING,
            'progress' => 0,
            'image_count' => 3,
            ...$attributes,
        ]);
    }
}
