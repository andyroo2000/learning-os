<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ShowContentAudioGenerationJobAction;
use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Domain\Content\Support\ContentAudioRateLimiter;
use App\Domain\Content\Support\ContentEpisodeAudio;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentAudioGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContentAudioGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->convoLabUserId = (string) Str::uuid();
    }

    public function test_routes_require_a_first_party_browser_session(): void
    {
        $this->postJson('/api/convolab/audio/generate', [])->assertUnauthorized();
        $this->postJson('/api/convolab/audio/generate-all-speeds', [])->assertUnauthorized();
        $this->getJson('/api/convolab/audio/job/'.Str::uuid())->assertUnauthorized();
        $this->getJson('/api/convolab/episodes/'.Str::uuid().'/audio/1.0')->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['content:write'])->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/convolab/audio/generate', $this->payload(Str::uuid(), Str::uuid()))
            ->assertForbidden();
        $this->withToken($token)
            ->getJson('/api/convolab/audio/job/'.Str::uuid())
            ->assertForbidden();
    }

    public function test_single_generation_normalizes_validated_input_and_queues_a_durable_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);

        $response = $this->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/convolab/audio/generate', [
                'episodeId' => '  '.strtoupper($episode->id).'  ',
                'dialogueId' => '  '.strtoupper($dialogue->id).'  ',
                'speed' => '  medium  ',
                'pauseMode' => true,
                'untrusted' => 'discarded',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Audio generation started');

        $job = ContentAudioGenerationJob::query()->findOrFail($response->json('jobId'));
        $this->assertSame($episode->id, $job->episode_id);
        $this->assertSame($dialogue->id, $job->dialogue_id);
        $this->assertSame(1, $job->attempt);
        $this->assertSame('single', $job->input['mode']);
        $this->assertSame('medium', $job->input['speed']);
        $this->assertTrue($job->input['pauseMode']);
        $this->assertArrayNotHasKey('untrusted', $job->input);
        $this->assertSame(1, $episode->fresh()->audio_generation_attempt);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
        Queue::assertPushed(
            ProcessContentAudioGeneration::class,
            fn (ProcessContentAudioGeneration $queued): bool => $queued->jobId === $job->id,
        );
    }

    public function test_all_speed_generation_deduplicates_nonterminal_episode_jobs(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);
        $payload = $this->payload($episode->id, $dialogue->id);

        $first = $this->postJson('/api/convolab/audio/generate-all-speeds', $payload)
            ->assertOk()
            ->assertJsonMissing(['existing' => true]);
        $second = $this->postJson('/api/convolab/audio/generate-all-speeds', $payload)
            ->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('message', 'Audio generation already in progress');

        $this->assertSame($first->json('jobId'), $second->json('jobId'));
        $this->assertDatabaseCount('content_audio_generation_jobs', 1);
        $this->assertSame(1, $episode->fresh()->audio_generation_attempt);
        Queue::assertPushed(ProcessContentAudioGeneration::class, 1);
    }

    public function test_generation_rejects_cross_mode_dedup_in_both_directions(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);
        $payload = $this->payload($episode->id, $dialogue->id);

        $single = $this->postJson('/api/convolab/audio/generate', [...$payload, 'speed' => 'medium'])
            ->assertOk();
        $this->postJson('/api/convolab/audio/generate-all-speeds', $payload)
            ->assertConflict()
            ->assertExactJson(['message' => 'Different audio generation is already in progress']);

        $singleJob = ContentAudioGenerationJob::query()->findOrFail($single->json('jobId'));
        $singleJob->state = ContentAudioGeneration::STATE_FAILED;
        $singleJob->finished_at = now();
        $singleJob->save();

        $allSpeeds = $this->postJson('/api/convolab/audio/generate-all-speeds', $payload)->assertOk();
        $this->postJson('/api/convolab/audio/generate', [...$payload, 'speed' => 'medium'])
            ->assertConflict()
            ->assertExactJson(['message' => 'Different audio generation is already in progress']);

        $this->assertNotSame($single->json('jobId'), $allSpeeds->json('jobId'));
        $this->assertDatabaseCount('content_audio_generation_jobs', 2);
        Queue::assertPushed(ProcessContentAudioGeneration::class, 2);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_generation_rejects_invalid_input_without_side_effects(string $route, array $payload, array $errors): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);

        $this->postJson($route, [...$this->payload($episode->id, $dialogue->id), ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('content_audio_generation_jobs', 0);
        $this->assertSame(0, $episode->fresh()->audio_generation_attempt);
        Queue::assertNothingPushed();
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'bad episode' => ['/api/convolab/audio/generate', ['episodeId' => 'bad'], ['episodeId']],
            'bad dialogue' => ['/api/convolab/audio/generate-all-speeds', ['dialogueId' => 'bad'], ['dialogueId']],
            'bad speed' => ['/api/convolab/audio/generate', ['speed' => 'fast'], ['speed']],
            'non-boolean pause' => ['/api/convolab/audio/generate', ['pauseMode' => 'yes'], ['pauseMode']],
        ];
    }

    public function test_generation_hides_mismatched_ownership_and_recovers_from_dispatch_failure(): void
    {
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user);
        $this->authenticateWrite($user);
        $payload = $this->payload($episode->id, $dialogue->id);

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($other)
            ->postJson('/api/convolab/audio/generate-all-speeds', $payload)
            ->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($user);
        $payload['dialogueId'] = (string) Str::uuid();
        $this->postJson('/api/convolab/audio/generate-all-speeds', $payload)->assertNotFound();
        $this->assertDatabaseCount('content_audio_generation_jobs', 0);

        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis secret.'));
        $this->postJson(
            '/api/convolab/audio/generate-all-speeds',
            $this->payload($episode->id, $dialogue->id),
        )->assertServiceUnavailable()->assertExactJson([
            'message' => ContentAudioGeneration::QUEUE_FAILED_MESSAGE,
        ]);

        $job = ContentAudioGenerationJob::query()->sole();
        $this->assertSame(ContentAudioGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentAudioGeneration::QUEUE_FAILED_MESSAGE, $job->error_message);
        $this->assertNotNull($job->finished_at);
    }

    public function test_polling_is_owner_scoped_exact_and_one_query(): void
    {
        $user = User::factory()->create();
        [$episode, $dialogue] = $this->episodeWithDialogue($user, ['audio_generation_attempt' => 1]);
        $job = $this->job($episode, $dialogue, ['state' => 'active', 'progress' => 35]);
        $this->authenticateWrite($user);

        $this->getJson('/api/convolab/audio/job/'.strtoupper($job->id))->assertExactJson([
            'id' => $job->id,
            'state' => 'active',
            'progress' => 35,
            'result' => null,
        ]);
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser(User::factory()->create())
            ->getJson('/api/convolab/audio/job/'.$job->id)
            ->assertNotFound();

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $found = app(ShowContentAudioGenerationJobAction::class)->handle(
                $user->id,
                $this->convoLabUserId,
                $job->id,
            );
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertNotNull($found);
        $this->assertCount(1, $queries);

        $job->state = 'completed';
        $job->progress = 100;
        $job->result = [['speed' => 'normal', 'audioUrl' => '/audio', 'duration' => 1000]];
        $job->save();
        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($user);
        $this->getJson('/api/convolab/audio/job/'.$job->id)
            ->assertJsonPath('result.0.speed', 'normal');
    }

    public function test_episode_audio_download_is_owner_scoped_path_allowlisted_and_missing_safe(): void
    {
        Storage::fake('media');
        config()->set('content_audio.disk', 'media');
        $user = User::factory()->create();
        [$episode] = $this->episodeWithDialogue($user);
        $path = ContentEpisodeAudio::storagePath($episode->id, 1, ContentEpisodeAudio::TRACK_NORMAL);
        $episode->audio_storage_path_1_0 = $path;
        $episode->save();
        Storage::disk('media')->put($path, 'mp3-bytes');
        $this->authenticateWrite($user);

        $this->get('/api/convolab/episodes/'.strtoupper($episode->id).'/audio/1.0')
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser(User::factory()->create())
            ->get('/api/convolab/episodes/'.$episode->id.'/audio/1.0')
            ->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($user);
        Storage::disk('media')->delete($path);
        $this->get('/api/convolab/episodes/'.$episode->id.'/audio/1.0')->assertNotFound();
        $this->get('/api/convolab/episodes/'.$episode->id.'/audio/unknown')->assertNotFound();
    }

    public function test_generation_rate_limit_and_server_owned_fields_are_protected(): void
    {
        $request = Request::create('/api/convolab/audio/generate', 'POST');
        $request->setUserResolver(
            fn (): User => User::factory()->make([
                'convolab_id' => strtoupper($this->convoLabUserId),
            ]),
        );
        $limit = ContentAudioRateLimiter::generation($request);
        $this->assertSame(6, $limit->maxAttempts);
        $this->assertSame(
            ContentAudioRateLimiter::GENERATION_NAME.':user:'.$this->convoLabUserId,
            $limit->key,
        );
        $fallback = Request::create('/api/convolab/audio/generate', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $fallback->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });
        $this->assertSame(
            ContentAudioRateLimiter::GENERATION_NAME.':user:42',
            ContentAudioRateLimiter::generation($fallback)->key,
        );
        foreach (['api/convolab/audio/generate', 'api/convolab/audio/generate-all-speeds'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($candidate): bool => $candidate->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.ContentAudioRateLimiter::GENERATION_NAME, $route->gatherMiddleware());
        }

        foreach ([
            [ContentEpisode::class, 'audio_generation_attempt'],
            [ContentEpisode::class, 'audio_storage_path'],
            [ContentEpisode::class, 'audio_storage_path_0_7'],
            [ContentEpisode::class, 'audio_storage_path_0_85'],
            [ContentEpisode::class, 'audio_storage_path_1_0'],
            [ContentAudioGenerationJob::class, 'id'],
            [ContentAudioGenerationJob::class, 'episode_id'],
            [ContentAudioGenerationJob::class, 'dialogue_id'],
            [ContentAudioGenerationJob::class, 'user_id'],
            [ContentAudioGenerationJob::class, 'convolab_user_id'],
            [ContentAudioGenerationJob::class, 'attempt'],
            [ContentAudioGenerationJob::class, 'state'],
            [ContentAudioGenerationJob::class, 'progress'],
            [ContentAudioGenerationJob::class, 'input'],
            [ContentAudioGenerationJob::class, 'result'],
            [ContentAudioGenerationJob::class, 'error_message'],
            [ContentAudioGenerationJob::class, 'started_at'],
            [ContentAudioGenerationJob::class, 'finished_at'],
        ] as [$model, $field]) {
            try {
                (new $model)->fill([$field => 'untrusted']);
                $this->fail("{$field} should not be mass assignable.");
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_single_and_bulk_routes_share_capacity_without_cross_user_starvation(): void
    {
        Queue::fake();
        $firstUser = User::factory()->create();
        [$firstEpisode, $firstDialogue] = $this->episodeWithDialogue($firstUser);
        $this->authenticateWrite($firstUser);
        $payload = $this->payload($firstEpisode->id, $firstDialogue->id);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/convolab/audio/generate', $payload)->assertOk();
        }
        $this->postJson('/api/convolab/audio/generate-all-speeds', $payload)->assertTooManyRequests();

        $this->convoLabUserId = (string) Str::uuid();
        $secondUser = User::factory()->create();
        [$secondEpisode, $secondDialogue] = $this->episodeWithDialogue($secondUser);
        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($secondUser);
        $this->postJson(
            '/api/convolab/audio/generate-all-speeds',
            $this->payload($secondEpisode->id, $secondDialogue->id),
        )->assertOk();
    }

    private function authenticateWrite(User $user): void
    {
        $this->asConvoLabBrowser($user, convoLabUserId: $this->convoLabUserId);
    }

    /** @param array<string, mixed> $episodeAttributes
     * @return array{ContentEpisode, ContentDialogue}
     */
    private function episodeWithDialogue(User $user, array $episodeAttributes = []): array
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
            ...$episodeAttributes,
        ]);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
        ]);

        return [$episode, $dialogue];
    }

    /** @param array<string, mixed> $attributes */
    private function job(ContentEpisode $episode, ContentDialogue $dialogue, array $attributes = []): ContentAudioGenerationJob
    {
        return ContentAudioGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'dialogue_id' => $dialogue->id,
            'user_id' => $episode->user_id,
            'convolab_user_id' => $episode->convolab_user_id,
            'attempt' => 1,
            'state' => 'waiting',
            'progress' => 0,
            'input' => [...$this->payload($episode->id, $dialogue->id), 'mode' => 'all-speeds'],
            ...$attributes,
        ]);
    }

    /** @return array{episodeId: string, dialogueId: string} */
    private function payload(string $episodeId, string $dialogueId): array
    {
        return ['episodeId' => $episodeId, 'dialogueId' => $dialogueId];
    }
}
