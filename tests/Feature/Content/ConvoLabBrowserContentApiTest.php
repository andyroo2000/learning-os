<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabBrowserContentApiTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'https://convo-lab.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['convo-lab.test']);
    }

    public function test_browser_session_reads_only_its_own_content_without_a_proxy_header(): void
    {
        [$user, $convoLabId] = $this->projectedUser();
        [$other, $otherConvoLabId] = $this->projectedUser();
        $owned = $this->episodeFor($user, $convoLabId, 'Owned');
        $otherEpisode = $this->episodeFor($other, $otherConvoLabId, 'Other');

        $this->asBrowser($user)
            ->getJson('/api/convolab/episodes/'.$owned->id)
            ->assertOk()
            ->assertJsonPath('id', $owned->id)
            ->assertJsonPath('userId', $convoLabId);

        $this->asBrowser($user)
            ->getJson('/api/convolab/episodes/'.$otherEpisode->id)
            ->assertNotFound();
    }

    public function test_browser_session_ignores_a_spoofed_proxy_identity_header(): void
    {
        [$user, $convoLabId] = $this->projectedUser();
        [$other, $otherConvoLabId] = $this->projectedUser();
        $owned = $this->episodeFor($user, $convoLabId, 'Owned');
        $this->episodeFor($other, $otherConvoLabId, 'Other');

        $this->asBrowser($user)
            ->withHeader('X-Convo-Lab-User-Id', $otherConvoLabId)
            ->getJson('/api/convolab/episodes/'.$owned->id)
            ->assertOk()
            ->assertJsonPath('id', $owned->id)
            ->assertJsonPath('userId', $convoLabId);
    }

    public function test_browser_session_creates_content_with_its_projected_identity(): void
    {
        [$user, $convoLabId] = $this->projectedUser();

        $response = $this->asBrowser($user)
            ->postJson('/api/convolab/episodes', [
                'title' => 'Browser Episode',
                'sourceText' => 'Source text',
                'targetLanguage' => 'ja',
                'nativeLanguage' => 'en',
                'contentType' => 'dialogue',
            ])
            ->assertOk()
            ->assertJsonPath('userId', $convoLabId);

        $this->assertDatabaseHas('content_episodes', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabId,
            'title' => 'Browser Episode',
        ]);
    }

    public function test_browser_session_without_a_projected_identity_fails_before_writing(): void
    {
        $user = User::factory()->create();

        $this->asBrowser($user)
            ->postJson('/api/convolab/episodes', [
                'title' => 'Browser Episode',
                'sourceText' => 'Source text',
                'targetLanguage' => 'ja',
                'nativeLanguage' => 'en',
                'contentType' => 'dialogue',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);

        $this->assertDatabaseCount('content_episodes', 0);
    }

    private function asBrowser(User $user): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/');
    }

    /** @return array{User, string} */
    private function projectedUser(): array
    {
        $user = User::factory()->create();
        $convoLabId = (string) Str::uuid();

        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);

        return [$user->refresh(), $convoLabId];
    }

    private function episodeFor(
        User $user,
        string $convoLabId,
        string $title,
    ): ContentEpisode {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => $title,
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'audio_speed' => 'medium',
            'auto_generate_audio' => true,
            'status' => 'draft',
            'is_sample_content' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
