<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_ordinary_browser_read_does_not_query_the_account_projection(): void
    {
        [$user, $convoLabId] = $this->projectedUser();
        $owned = $this->episodeFor($user, $convoLabId, 'Owned');

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->asBrowser($user)
                ->getJson('/api/convolab/episodes/'.$owned->id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk();

        $this->assertFalse(
            collect($queries)->contains(
                fn (array $query): bool => str_contains($query['query'], 'admin_user_projections'),
            ),
            'Ordinary content reads should not load the account projection.',
        );
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
        [, $otherConvoLabId] = $this->projectedUser();

        $response = $this->asBrowser($user)
            ->withHeader('X-Convo-Lab-User-Id', $otherConvoLabId)
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

    public function test_admin_browser_session_can_read_and_create_for_a_view_as_target(): void
    {
        [$admin, $adminConvoLabId] = $this->projectedUser('admin');
        [$target, $targetConvoLabId] = $this->projectedUser();
        $targetEpisode = $this->episodeFor($target, $targetConvoLabId, 'Target episode');

        $this->asBrowser($admin)
            ->withHeader('User-Agent', 'Convo Lab browser test')
            ->getJson('/api/convolab/episodes/'.$targetEpisode->id.'?viewAs='.strtoupper($targetConvoLabId))
            ->assertOk()
            ->assertJsonPath('id', $targetEpisode->id)
            ->assertJsonPath('userId', $targetConvoLabId);

        $response = $this->asBrowser($admin)
            ->postJson('/api/convolab/episodes?viewAs='.$targetConvoLabId, [
                'title' => 'Impersonated create',
                'sourceText' => 'Source text',
                'targetLanguage' => 'ja',
                'nativeLanguage' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('userId', $targetConvoLabId);

        $this->assertDatabaseHas('content_episodes', [
            'id' => $response->json('id'),
            'user_id' => $target->id,
            'convolab_user_id' => $targetConvoLabId,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 2);

        $audit = DB::table('admin_audit_logs')->orderBy('createdAt')->first();
        $this->assertNotNull($audit);
        $this->assertSame($adminConvoLabId, $audit->adminUserId);
        $this->assertSame($targetConvoLabId, $audit->targetUserId);
        $this->assertSame('impersonate_start', $audit->action);
        $this->assertSame('Convo Lab browser test', $audit->userAgent);
        $this->assertSame([
            'path' => 'api/convolab/episodes/'.$targetEpisode->id,
            'method' => 'GET',
            'query' => ['viewAs' => strtoupper($targetConvoLabId)],
        ], json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_view_as_rejects_non_admin_actor_without_an_audit_row(): void
    {
        [$user] = $this->projectedUser();
        $missingTarget = (string) Str::uuid();

        $this->asBrowser($user)
            ->getJson('/api/convolab/episodes/'.Str::uuid().'?viewAs='.$missingTarget)
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized impersonation attempt');

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_view_as_rejects_a_malformed_target_without_an_audit_row(): void
    {
        [$admin] = $this->projectedUser('admin');

        $this->asBrowser($admin)
            ->getJson('/api/convolab/episodes/'.Str::uuid().'?viewAs[]=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['viewAs']);

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_view_as_hides_a_missing_target_without_an_audit_row(): void
    {
        [$admin] = $this->projectedUser('admin');
        $missingTarget = (string) Str::uuid();

        $this->asBrowser($admin)
            ->getJson('/api/convolab/episodes/'.Str::uuid().'?viewAs='.$missingTarget)
            ->assertNotFound();

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_view_as_read_succeeds_when_best_effort_audit_persistence_fails(): void
    {
        [$admin] = $this->projectedUser('admin');
        [$target, $targetConvoLabId] = $this->projectedUser();
        $targetEpisode = $this->episodeFor($target, $targetConvoLabId, 'Target episode');
        Schema::drop('admin_audit_logs');

        $this->asBrowser($admin)
            ->getJson('/api/convolab/episodes/'.$targetEpisode->id.'?viewAs='.$targetConvoLabId)
            ->assertOk()
            ->assertJsonPath('id', $targetEpisode->id);
    }

    public function test_demo_browser_session_cannot_create_or_delete_but_retains_legacy_update_access(): void
    {
        [$demo, $demoConvoLabId] = $this->projectedUser('demo');
        $episode = $this->episodeFor($demo, $demoConvoLabId, 'Demo episode');

        $this->asBrowser($demo)
            ->postJson('/api/convolab/episodes', [
                'title' => 'Blocked create',
                'sourceText' => 'Source text',
                'targetLanguage' => 'ja',
                'nativeLanguage' => 'en',
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                "You're exploring in demo mode, so content creation is disabled. "
                ."Thanks for checking out the app! If you'd like full access, please contact the admin.",
            );

        $this->asBrowser($demo)
            ->deleteJson('/api/convolab/episodes/'.$episode->id)
            ->assertForbidden();

        $this->asBrowser($demo)
            ->patchJson('/api/convolab/episodes/'.$episode->id, ['title' => 'Updated by demo'])
            ->assertOk()
            ->assertJsonPath('message', 'Episode updated successfully');

        $this->assertDatabaseHas('content_episodes', [
            'id' => $episode->id,
            'title' => 'Updated by demo',
        ]);
    }

    public function test_admin_view_as_demo_retains_legacy_support_mutation_access(): void
    {
        [$admin] = $this->projectedUser('admin');
        [$demo, $demoConvoLabId] = $this->projectedUser('demo');
        $existing = $this->episodeFor($demo, $demoConvoLabId, 'Demo episode');

        $response = $this->asBrowser($admin)
            ->postJson('/api/convolab/episodes?viewAs='.$demoConvoLabId, [
                'title' => 'Admin support create',
                'sourceText' => 'Source text',
                'targetLanguage' => 'ja',
                'nativeLanguage' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('userId', $demoConvoLabId);

        $this->asBrowser($admin)
            ->deleteJson('/api/convolab/episodes/'.$existing->id.'?viewAs='.$demoConvoLabId)
            ->assertOk()
            ->assertJsonPath('message', 'Episode deleted successfully');

        $this->assertDatabaseHas('content_episodes', [
            'id' => $response->json('id'),
            'user_id' => $demo->id,
            'convolab_user_id' => $demoConvoLabId,
        ]);
        $this->assertDatabaseMissing('content_episodes', ['id' => $existing->id]);
        $this->assertDatabaseCount('admin_audit_logs', 2);
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

    public function test_content_api_rejects_bearer_tokens_with_a_stateful_origin(): void
    {
        [$user, $convoLabId] = $this->projectedUser();
        $bearer = $user->createToken('mobile', ['content:write'])->plainTextToken;

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/')
            ->withHeader('X-Convo-Lab-User-Id', $convoLabId)
            ->withToken($bearer)
            ->postJson('/api/convolab/episodes', [
                'title' => 'Bearer Episode',
                'sourceText' => 'Source text',
                'targetLanguage' => 'ja',
                'nativeLanguage' => 'en',
                'contentType' => 'dialogue',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_proxy_bearer_identity_ignores_browser_view_as_query_parameter(): void
    {
        [$user, $convoLabId] = $this->projectedUser();
        [, $otherConvoLabId] = $this->projectedUser();
        $owned = $this->episodeFor($user, $convoLabId, 'Owned');
        $bearer = $user->createToken('convolab-proxy', ['content:write'])->plainTextToken;

        $this->withToken($bearer)
            ->withHeader('X-Convo-Lab-User-Id', $convoLabId)
            ->getJson('/api/convolab/episodes/'.$owned->id.'?viewAs='.$otherConvoLabId)
            ->assertOk()
            ->assertJsonPath('id', $owned->id)
            ->assertJsonPath('userId', $convoLabId);

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    private function asBrowser(User $user): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/');
    }

    /** @return array{User, string} */
    private function projectedUser(string $role = 'user'): array
    {
        $convoLabId = (string) Str::uuid();
        $email = $convoLabId.'@example.com';
        $user = User::factory()->create(['email' => $email]);

        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);
        DB::table('admin_user_projections')->insert([
            'convolab_id' => $convoLabId,
            'user_id' => $user->id,
            'email' => $email,
            'name' => 'Browser User',
            'display_name' => null,
            'avatar_color' => 'indigo',
            'avatar_url' => null,
            'role' => $role,
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'proficiency_level' => 'beginner',
            'onboarding_completed' => false,
            'seen_sample_content_guide' => false,
            'seen_custom_content_guide' => false,
            'email_verified' => true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'source_system' => 'learning_os',
            'avatar_source_system' => 'learning_os',
        ]);

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
