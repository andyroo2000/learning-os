<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentEpisodeWriteApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_EMAIL = 'proxy@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', self::PROXY_EMAIL);
    }

    public function test_episode_writes_require_authentication(): void
    {
        $episodeId = (string) Str::uuid();

        $this->postJson('/api/convolab/episodes')->assertUnauthorized();
        $this->patchJson('/api/convolab/episodes/'.$episodeId)->assertUnauthorized();
        $this->deleteJson('/api/convolab/episodes/'.$episodeId)->assertUnauthorized();
    }

    public function test_episode_writes_require_the_dedicated_proxy_token_and_content_scope(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $episodeId = (string) Str::uuid();

        $ordinaryToken = $user->createToken('mobile', ['content:write'])->plainTextToken;
        $this->withToken($ordinaryToken)
            ->postJson('/api/convolab/episodes', $this->validPayload())
            ->assertForbidden();

        $readOnlyProxyToken = $user->createToken('convolab-proxy', ['study:read'])->plainTextToken;
        $this->withToken($readOnlyProxyToken)
            ->patchJson('/api/convolab/episodes/'.$episodeId, ['title' => 'Updated'])
            ->assertForbidden();

        config()->set('services.convolab.proxy_user_email', 'different@example.com');
        $writeProxyToken = $user->createToken('convolab-proxy', ['content:write'])->plainTextToken;
        $this->withToken($writeProxyToken)
            ->deleteJson('/api/convolab/episodes/'.$episodeId)
            ->assertForbidden();
    }

    public function test_proxy_creates_a_convolab_compatible_episode_with_normalized_provenance(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $sourceUserId = strtoupper((string) Str::uuid());

        $response = $this->withToken($this->proxyToken($user))
            ->withHeader('X-Convo-Lab-User-Id', '  '.$sourceUserId.'  ')
            ->postJson('/api/convolab/episodes', [
                ...$this->validPayload(),
                'audioSpeed' => 'slow',
                'jlptLevel' => 'N3',
                'autoGenerateAudio' => false,
            ])
            ->assertOk()
            ->assertJsonPath('userId', strtolower($sourceUserId))
            ->assertJsonPath('title', 'New Episode')
            ->assertJsonPath('sourceText', 'Source text')
            ->assertJsonPath('targetLanguage', 'ja')
            ->assertJsonPath('nativeLanguage', 'en')
            ->assertJsonPath('contentType', 'dialogue')
            ->assertJsonPath('audioSpeed', 'slow')
            ->assertJsonPath('jlptLevel', 'N3')
            ->assertJsonPath('autoGenerateAudio', false)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('isSampleContent', false)
            ->assertJsonMissingPath('dialogue');

        $episodeId = $response->json('id');
        $this->assertIsString($episodeId);
        $this->assertTrue(Str::isUuid($episodeId));
        $this->assertDatabaseHas('content_episodes', [
            'id' => $episodeId,
            'user_id' => $user->id,
            'convolab_user_id' => strtolower($sourceUserId),
            'title' => 'New Episode',
            'audio_speed' => 'slow',
            'jlpt_level' => 'N3',
            'auto_generate_audio' => false,
            'status' => 'draft',
        ]);
    }

    public function test_create_applies_legacy_defaults(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);

        $response = $this->withToken($this->proxyToken($user))
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson('/api/convolab/episodes', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('audioSpeed', 'medium')
            ->assertJsonPath('jlptLevel', null)
            ->assertJsonPath('autoGenerateAudio', true);

        $this->assertDatabaseHas('content_episodes', [
            'id' => $response->json('id'),
            'audio_speed' => 'medium',
            'jlpt_level' => null,
            'auto_generate_audio' => true,
        ]);
    }

    public function test_create_validates_the_trusted_user_header_and_legacy_input_domain(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $token = $this->proxyToken($user);

        $this->withToken($token)
            ->postJson('/api/convolab/episodes', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->postJson('/api/convolab/episodes', [
                'title' => ['not a string'],
                'sourceText' => null,
                'targetLanguage' => 'fr',
                'nativeLanguage' => 'ja',
                'audioSpeed' => 'fast',
                'jlptLevel' => 'N0',
                'autoGenerateAudio' => 'maybe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'convolabUserId',
                'title',
                'sourceText',
                'targetLanguage',
                'nativeLanguage',
                'audioSpeed',
                'jlptLevel',
                'autoGenerateAudio',
            ]);

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_proxy_updates_only_supplied_fields_and_hides_other_owners(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $owned = $this->episodeFor($user, ['title' => 'Original', 'status' => 'draft']);
        $other = $this->episodeFor($user, ['title' => 'Other']);
        $token = $this->proxyToken($user);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', strtoupper($owned->convolab_user_id))
            ->patchJson('/api/convolab/episodes/'.strtoupper($owned->id), ['title' => 'Updated'])
            ->assertOk()
            ->assertExactJson(['message' => 'Episode updated successfully']);

        $owned->refresh();
        $this->assertSame('Updated', $owned->title);
        $this->assertSame('draft', $owned->status);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $owned->convolab_user_id)
            ->patchJson('/api/convolab/episodes/'.$other->id, ['status' => 'ready'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Episode not found']);
        $this->assertSame('draft', $other->fresh()->status);
    }

    public function test_update_requires_effective_user_provenance_and_rejects_unknown_statuses(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $episode = $this->episodeFor($user);
        $token = $this->proxyToken($user);

        $this->withToken($token)
            ->patchJson('/api/convolab/episodes/'.$episode->id, ['status' => 'ready'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $episode->convolab_user_id)
            ->patchJson('/api/convolab/episodes/'.$episode->id, ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('draft', $episode->fresh()->status);
    }

    public function test_empty_update_preserves_legacy_touch_behavior(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $episode = $this->episodeFor($user);
        $originalUpdatedAt = $episode->updated_at;

        $this->travel(1)->second();
        try {
            $this->withToken($this->proxyToken($user))
                ->withHeader('X-Convo-Lab-User-Id', $episode->convolab_user_id)
                ->patchJson('/api/convolab/episodes/'.$episode->id, [])
                ->assertOk();
        } finally {
            $this->travelBack();
        }

        $this->assertTrue($episode->fresh()->updated_at->isAfter($originalUpdatedAt));
    }

    public function test_proxy_deletes_owned_episode_graph_and_hides_retries_and_other_owners(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $owned = $this->episodeFor($user);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $owned->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $other = $this->episodeFor($user);
        $token = $this->proxyToken($user);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $owned->convolab_user_id)
            ->deleteJson('/api/convolab/episodes/'.$owned->id)
            ->assertOk()
            ->assertExactJson(['message' => 'Episode deleted successfully']);
        $this->assertDatabaseMissing('content_episodes', ['id' => $owned->id]);
        $this->assertDatabaseMissing('content_dialogues', ['id' => $dialogue->id]);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $owned->convolab_user_id)
            ->deleteJson('/api/convolab/episodes/'.$owned->id)
            ->assertNotFound();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $owned->convolab_user_id)
            ->deleteJson('/api/convolab/episodes/'.$other->id)
            ->assertNotFound();
        $this->assertDatabaseHas('content_episodes', ['id' => $other->id]);
    }

    public function test_episode_write_routes_use_separate_named_rate_limiters(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ([
            ['POST', 'api/convolab/episodes', ContentEpisodeRateLimiter::CREATE_NAME],
            ['PATCH', 'api/convolab/episodes/{episodeId}', ContentEpisodeRateLimiter::UPDATE_NAME],
            ['DELETE', 'api/convolab/episodes/{episodeId}', ContentEpisodeRateLimiter::DELETE_NAME],
        ] as [$method, $uri, $limiter]) {
            $route = $routes->first(fn ($route): bool => in_array($method, $route->methods(), true) && $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
    }

    public function test_create_and_update_rate_limits_do_not_share_a_bucket(): void
    {
        $user = User::factory()->create(['email' => self::PROXY_EMAIL]);
        $episode = $this->episodeFor($user);
        $token = $this->proxyToken($user);
        $sourceUserId = (string) Str::uuid();
        $otherSourceUserId = (string) Str::uuid();
        $testBucket = 'episode-write-test-'.Str::uuid();

        RateLimiter::for(ContentEpisodeRateLimiter::CREATE_NAME, function (Request $request) use ($testBucket): Limit {
            return Limit::perMinute(1)->by($testBucket.'|create|'.strtolower(trim((string) $request->header('X-Convo-Lab-User-Id'))));
        });
        RateLimiter::for(ContentEpisodeRateLimiter::UPDATE_NAME, function (Request $request) use ($testBucket): Limit {
            return Limit::perMinute(1)->by($testBucket.'|update|'.strtolower(trim((string) $request->header('X-Convo-Lab-User-Id'))));
        });

        try {
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $sourceUserId)
                ->postJson('/api/convolab/episodes', $this->validPayload())
                ->assertOk();
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $sourceUserId)
                ->postJson('/api/convolab/episodes', $this->validPayload())
                ->assertTooManyRequests();
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $otherSourceUserId)
                ->postJson('/api/convolab/episodes', $this->validPayload())
                ->assertOk();
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $episode->convolab_user_id)
                ->patchJson('/api/convolab/episodes/'.$episode->id, ['status' => 'ready'])
                ->assertOk();
        } finally {
            RateLimiter::clear($testBucket.'|create|'.$sourceUserId);
            RateLimiter::clear($testBucket.'|create|'.$otherSourceUserId);
            RateLimiter::clear($testBucket.'|update|'.$episode->convolab_user_id);
            $this->restoreEpisodeRateLimiters();
        }
    }

    private function proxyToken(User $user): string
    {
        return $user->createToken('convolab-proxy', ['content:write'])->plainTextToken;
    }

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'title' => 'New Episode',
            'sourceText' => 'Source text',
            'targetLanguage' => 'ja',
            'nativeLanguage' => 'en',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function episodeFor(User $user, array $overrides = []): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'title' => 'Episode',
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'jlpt_level' => null,
            'auto_generate_audio' => true,
            'status' => 'draft',
            'is_sample_content' => false,
            'audio_speed' => 'medium',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
            ...$overrides,
        ]);
    }

    private function restoreEpisodeRateLimiters(): void
    {
        foreach ([
            ContentEpisodeRateLimiter::CREATE_NAME => ContentEpisodeRateLimiter::create(),
            ContentEpisodeRateLimiter::UPDATE_NAME => ContentEpisodeRateLimiter::update(),
        ] as $name => $limiter) {
            RateLimiter::for($name, fn (Request $request): Limit => $limiter->limit($request));
        }
    }
}
