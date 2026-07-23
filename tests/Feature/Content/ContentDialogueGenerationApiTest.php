<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ShowContentDialogueGenerationJobAction;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentDialogueRateLimiter;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentDialogueGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContentDialogueGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->convoLabUserId = (string) Str::uuid();
    }

    public function test_routes_require_authentication_and_generation_requires_the_proxy_write_ability(): void
    {
        $this->postJson('/api/convolab/dialogue/generate', [])->assertUnauthorized();
        $this->getJson('/api/convolab/dialogue/job/'.Str::uuid())->assertUnauthorized();

        $user = User::factory()->create();
        config()->set('services.convolab.proxy_user_email', 'another@example.com');
        $token = $user->createToken('mobile', ['content:write'])->plainTextToken;
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->postJson('/api/convolab/dialogue/generate', $this->payload((string) Str::uuid()))
            ->assertForbidden();

        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', '')
            ->getJson('/api/convolab/dialogue/job/'.Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
    }

    public function test_generate_normalizes_validated_input_and_atomically_queues_a_durable_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $episode = $this->episode($user);
        $this->authenticateWrite($user);

        $payload = $this->payload(strtoupper($episode->id));
        $payload['speakers'][0]['name'] = '  Aiko [F]  ';
        $payload['variationCount'] = '+3';
        $payload['dialogueLength'] = '6';
        $payload['vocabSeedOverride'] = '  travel  ';

        $response = $this->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/convolab/dialogue/generate', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Dialogue generation started');

        $jobId = $response->json('jobId');
        $this->assertTrue(Str::isUuid($jobId));
        $job = ContentDialogueGenerationJob::query()->findOrFail($jobId);
        $this->assertSame($episode->id, $job->episode_id);
        $this->assertSame(1, $job->attempt);
        $this->assertSame(ContentDialogueGeneration::STATE_WAITING, $job->state);
        $this->assertSame(0, $job->progress);
        $this->assertSame('Aiko [F]', $job->input['speakers'][0]['name']);
        $this->assertSame(3, $job->input['variationCount']);
        $this->assertSame(6, $job->input['dialogueLength']);
        $this->assertSame('travel', $job->input['vocabSeedOverride']);

        $episode->refresh();
        $this->assertSame('generating', $episode->status);
        $this->assertSame(1, $episode->dialogue_generation_attempt);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->source_system);
        $this->assertDatabaseHas('generation_logs', [
            'userId' => $this->convoLabUserId,
            'contentType' => 'dialogue',
            'contentId' => $episode->id,
        ]);
        Queue::assertPushed(
            ProcessContentDialogueGeneration::class,
            fn (ProcessContentDialogueGeneration $queued): bool => $queued->jobId === $jobId,
        );

        $this->postJson('/api/convolab/dialogue/generate', $this->payload($episode->id))
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Dialogue is already being generated']);
        $this->assertDatabaseCount('generation_logs', 1);
        Queue::assertPushed(ProcessContentDialogueGeneration::class, 1);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_generate_rejects_invalid_bounded_input_without_side_effects(array $changes, array $errors): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $episode = $this->episode($user);
        $this->authenticateWrite($user);
        $payload = $this->payload($episode->id);
        foreach ($changes as $key => $value) {
            data_set($payload, $key, $value);
        }

        $this->postJson('/api/convolab/dialogue/generate', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('content_dialogue_generation_jobs', 0);
        $this->assertSame('draft', $episode->fresh()->status);
        Queue::assertNothingPushed();
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'too few speakers' => [['speakers' => [[
                'name' => 'Only one', 'voiceId' => 'Takumi', 'proficiency' => 'N4', 'tone' => 'casual',
            ]]], ['speakers']],
            'unknown speaker key' => [['speakers.0.secret' => true], ['speakers.0']],
            'zero variations' => [['variationCount' => 0], ['variationCount']],
            'too many lines' => [['dialogueLength' => 21], ['dialogueLength']],
            'invalid proficiency' => [['speakers.0.proficiency' => 'expert'], ['speakers.0.proficiency']],
            'duplicate prompt names' => [['speakers.1.name' => 'aiko [あいこ]'], ['speakers']],
            'annotation-only name' => [['speakers.1.name' => '[けん]'], ['speakers']],
            'non-string override' => [['vocabSeedOverride' => ['travel']], ['vocabSeedOverride']],
        ];
    }

    public function test_generate_hides_other_owners_and_recovers_from_queue_dispatch_failure(): void
    {
        $owner = User::factory()->create();
        $episode = $this->episode($owner);
        $this->authenticateWrite($owner);
        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid());

        $this->postJson('/api/convolab/dialogue/generate', $this->payload($episode->id))
            ->assertNotFound();
        $this->assertDatabaseCount('content_dialogue_generation_jobs', 0);
        $this->assertDatabaseCount('generation_logs', 0);
        $this->assertDatabaseCount('content_generation_cooldowns', 0);

        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis secret.'));
        $this->postJson('/api/convolab/dialogue/generate', $this->payload($episode->id))
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => ContentDialogueGeneration::QUEUE_FAILED_MESSAGE]);

        $job = ContentDialogueGenerationJob::query()->sole();
        $this->assertSame(ContentDialogueGeneration::STATE_FAILED, $job->state);
        $this->assertSame(ContentDialogueGeneration::QUEUE_FAILED_MESSAGE, $job->error_message);
        $this->assertNotNull($job->finished_at);
        $this->assertSame('error', $episode->fresh()->status);
        $this->assertDatabaseCount('generation_logs', 0);
        $this->assertDatabaseHas('content_generation_cooldowns', [
            'convolab_user_id' => $this->convoLabUserId,
        ]);
    }

    public function test_polling_is_owner_scoped_and_returns_durable_state_or_completed_result(): void
    {
        $user = User::factory()->create();
        $episode = $this->episode($user, ['status' => 'generating', 'dialogue_generation_attempt' => 2]);
        $job = $this->job($episode, ['attempt' => 2, 'state' => 'active', 'progress' => 10]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);

        $this->getJson('/api/convolab/dialogue/job/'.strtoupper($job->id))
            ->assertExactJson([
                'id' => $job->id,
                'state' => 'active',
                'progress' => 10,
                'result' => null,
            ]);

        $this->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->getJson("/api/convolab/dialogue/job/{$job->id}")
            ->assertNotFound();

        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
        ]);
        $job->state = 'completed';
        $job->progress = 100;
        $job->save();

        $this->getJson("/api/convolab/dialogue/job/{$job->id}")
            ->assertOk()
            ->assertJsonPath('result.dialogue.id', $dialogue->id)
            ->assertJsonPath('result.dialogue.episodeId', $episode->id)
            ->assertJsonPath('result.sentences', [])
            ->assertJsonPath('result.speakers', []);
    }

    public function test_pending_polling_does_not_load_the_episode_dialogue_graph(): void
    {
        $user = User::factory()->create();
        $episode = $this->episode($user, ['status' => 'generating', 'dialogue_generation_attempt' => 1]);
        $job = $this->job($episode);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(ShowContentDialogueGenerationJobAction::class)->handle(
                $user->id,
                $this->convoLabUserId,
                $job->id,
            );
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertNotNull($result);
        $this->assertCount(1, $queries, 'Pending polling should only query the durable job row.');
        $this->assertFalse($result->relationLoaded('episode'));
    }

    public function test_generation_rate_limiter_is_operation_scoped_and_wired_to_the_write_route(): void
    {
        $request = Request::create('/api/convolab/dialogue/generate', 'POST');
        $request->headers->set('X-Convo-Lab-User-Id', strtoupper($this->convoLabUserId));
        $limit = ContentDialogueRateLimiter::generation($request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(
            ContentDialogueRateLimiter::GENERATION_NAME.':user:'.$this->convoLabUserId,
            $limit->key,
        );
        $fallback = Request::create('/api/convolab/dialogue/generate', 'POST', [], [], [], [
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
            ContentDialogueRateLimiter::GENERATION_NAME.':user:42',
            ContentDialogueRateLimiter::generation($fallback)->key,
        );
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate): bool => $candidate->uri() === 'api/convolab/dialogue/generate');
        $this->assertNotNull($route);
        $this->assertContains('throttle:'.ContentDialogueRateLimiter::GENERATION_NAME, $route->gatherMiddleware());
    }

    #[DataProvider('protectedFieldProvider')]
    public function test_process_owned_fields_cannot_be_mass_assigned(string $model, string $field): void
    {
        $this->expectException(MassAssignmentException::class);
        (new $model)->fill([$field => 'untrusted']);
    }

    public static function protectedFieldProvider(): array
    {
        return [
            'episode attempt' => [ContentEpisode::class, 'dialogue_generation_attempt'],
            'job state' => [ContentDialogueGenerationJob::class, 'state'],
            'job progress' => [ContentDialogueGenerationJob::class, 'progress'],
            'job error' => [ContentDialogueGenerationJob::class, 'error_message'],
        ];
    }

    private function authenticateWrite(User $user): void
    {
        $this->convoLabProjectionFor($user, $this->convoLabUserId);
        config()->set('services.convolab.proxy_user_email', $user->email);
        $this->withToken($user->createToken('convolab-proxy', ['content:write'])->plainTextToken);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
    }

    /** @param array<string, mixed> $attributes */
    private function episode(User $user, array $attributes = []): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Draft dialogue',
            'source_text' => 'Two friends plan a trip.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'draft',
            'is_sample_content' => false,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function job(ContentEpisode $episode, array $attributes = []): ContentDialogueGenerationJob
    {
        return ContentDialogueGenerationJob::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'user_id' => $episode->user_id,
            'convolab_user_id' => $episode->convolab_user_id,
            'attempt' => 1,
            'state' => 'waiting',
            'progress' => 0,
            'input' => $this->payload($episode->id),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $episodeId): array
    {
        return [
            'episodeId' => $episodeId,
            'speakers' => [
                ['name' => 'Aiko', 'voiceId' => 'ja-JP-Neural2-B', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => '#112233'],
                ['name' => 'Ken', 'voiceId' => 'Takumi', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
            ],
            'variationCount' => 3,
            'dialogueLength' => 6,
            'jlptLevel' => 'N4',
        ];
    }
}
