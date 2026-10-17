<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentEpisodeWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_episode_writes_require_authentication(): void
    {
        $episodeId = (string) Str::uuid();

        $this->patchJson('/api/convolab/episodes/'.$episodeId)->assertUnauthorized();
        $this->deleteJson('/api/convolab/episodes/'.$episodeId)->assertUnauthorized();
    }

    public function test_episode_writes_reject_api_tokens(): void
    {
        $user = User::factory()->create();
        $episodeId = (string) Str::uuid();

        $ordinaryToken = $user->createToken('mobile', ['content:write'])->plainTextToken;
        $this->withToken($ordinaryToken)
            ->patchJson('/api/convolab/episodes/'.$episodeId, ['title' => 'Updated'])
            ->assertForbidden();

        $this->withToken($ordinaryToken)
            ->deleteJson('/api/convolab/episodes/'.$episodeId)
            ->assertForbidden();
    }

    public function test_browser_session_updates_only_supplied_fields_and_hides_other_owners(): void
    {
        $user = User::factory()->create();
        $owned = $this->episodeFor($user, ['title' => 'Original', 'status' => 'draft']);
        $other = $this->episodeFor($user, ['title' => 'Other']);

        $this->asConvoLabBrowser($user, convoLabUserId: strtoupper($owned->convolab_user_id))
            ->patchJson('/api/convolab/episodes/'.strtoupper($owned->id), ['title' => 'Updated'])
            ->assertOk()
            ->assertExactJson(['message' => 'Episode updated successfully']);

        $owned->refresh();
        $this->assertSame('Updated', $owned->title);
        $this->assertSame('draft', $owned->status);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $owned->source_system);

        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->patchJson('/api/convolab/episodes/'.$other->id, ['status' => 'ready'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Episode not found']);
        $this->assertSame('draft', $other->fresh()->status);
    }

    public function test_update_uses_session_provenance_and_rejects_unknown_statuses(): void
    {
        $user = User::factory()->create();
        $episode = $this->episodeFor($user);

        $this->asConvoLabBrowser($user, convoLabUserId: $episode->convolab_user_id)
            ->patchJson('/api/convolab/episodes/'.$episode->id, [
                'convolabUserId' => (string) Str::uuid(),
                'status' => 'completed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status'])
            ->assertJsonMissingValidationErrors(['convolabUserId']);

        $this->assertSame('draft', $episode->fresh()->status);
    }

    public function test_empty_update_preserves_legacy_touch_behavior(): void
    {
        $user = User::factory()->create();
        $episode = $this->episodeFor($user);
        $originalUpdatedAt = $episode->updated_at;

        $this->travel(1)->second();
        try {
            $this->asConvoLabBrowser($user, convoLabUserId: $episode->convolab_user_id)
                ->patchJson('/api/convolab/episodes/'.$episode->id, [])
                ->assertOk();
        } finally {
            $this->travelBack();
        }

        $this->assertTrue($episode->fresh()->updated_at->isAfter($originalUpdatedAt));
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
    }

    public function test_browser_session_deletes_owned_episode_graph_and_hides_retries_and_other_owners(): void
    {
        $user = User::factory()->create();
        $owned = $this->episodeFor($user);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $owned->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $other = $this->episodeFor($user);
        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->deleteJson('/api/convolab/episodes/'.$owned->id)
            ->assertOk()
            ->assertExactJson(['message' => 'Episode deleted successfully']);
        $this->assertDatabaseMissing('content_episodes', ['id' => $owned->id]);
        $this->assertDatabaseMissing('content_dialogues', ['id' => $dialogue->id]);
        $this->assertDatabaseHas('content_episode_tombstones', [
            'episode_id' => $owned->id,
            'user_id' => $user->id,
            'convolab_user_id' => $owned->convolab_user_id,
        ]);

        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->deleteJson('/api/convolab/episodes/'.$owned->id)
            ->assertNotFound();
        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->deleteJson('/api/convolab/episodes/'.$other->id)
            ->assertNotFound();
        $this->assertDatabaseHas('content_episodes', ['id' => $other->id]);
    }

    public function test_episode_write_routes_use_separate_named_rate_limiters(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ([
            ['PATCH', 'api/convolab/episodes/{episodeId}', ContentEpisodeRateLimiter::UPDATE_NAME],
            ['DELETE', 'api/convolab/episodes/{episodeId}', ContentEpisodeRateLimiter::DELETE_NAME],
        ] as [$method, $uri, $limiter]) {
            $route = $routes->first(fn ($route): bool => in_array($method, $route->methods(), true) && $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
    }

    /** @return array<string, string> */
    /** @param array<string, mixed> $overrides */
    private function episodeFor(User $user, array $overrides = []): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
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
}
