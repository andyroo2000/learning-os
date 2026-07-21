<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_profile_update_requires_the_named_proxy_identity_and_exact_write_scope(): void
    {
        $account = $this->projectedUser();
        $path = '/api/convolab/auth/me';

        $this->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->patchJson($path, ['displayName' => 'Ada'])
            ->assertUnauthorized();

        $this->withToken($this->proxyToken(['auth:read']))
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->patchJson($path, ['displayName' => 'Ada'])
            ->assertForbidden();

        $ordinaryToken = User::factory()->create()
            ->createToken('mobile', ['auth:write'])
            ->plainTextToken;
        $this->withToken($ordinaryToken)
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->patchJson($path, ['displayName' => 'Ada'])
            ->assertForbidden();

        $wildcardToken = User::factory()->create(['email' => 'wildcard@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
        $this->withToken($wildcardToken)
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->patchJson($path, ['displayName' => 'Ada'])
            ->assertForbidden();
    }

    public function test_profile_update_normalizes_the_identity_and_returns_the_exact_legacy_shape(): void
    {
        $account = $this->projectedUser([
            'avatar_url' => 'https://example.com/old.png',
            'seen_sample_content_guide' => true,
        ]);

        $response = $this->withoutMiddleware(TrimStrings::class)
            ->withToken($this->proxyToken(['auth:write']))
            ->withHeader('X-Convo-Lab-User-Id', "  \t".strtoupper($account['convolab_id'])."\n ")
            ->patchJson('/api/convolab/auth/me', [
                'displayName' => 'Ada',
                'avatarColor' => 'teal',
                'avatarUrl' => null,
                'seenCustomContentGuide' => true,
            ])
            ->assertOk()
            ->assertJsonCount(17);

        $response->assertJsonStructure([
            'id', 'email', 'name', 'displayName', 'avatarColor', 'avatarUrl', 'role',
            'preferredStudyLanguage', 'preferredNativeLanguage', 'proficiencyLevel',
            'onboardingCompleted', 'emailVerified', 'emailVerifiedAt', 'createdAt',
            'updatedAt', 'seenSampleContentGuide', 'seenCustomContentGuide',
        ]);
        $response->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('displayName', 'Ada')
            ->assertJsonPath('avatarColor', 'teal')
            ->assertJsonPath('avatarUrl', null)
            ->assertJsonPath('seenSampleContentGuide', true)
            ->assertJsonPath('seenCustomContentGuide', true);

        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $account['convolab_id'],
            'display_name' => 'Ada',
            'avatar_color' => 'teal',
            'avatar_url' => null,
            'seen_sample_content_guide' => true,
            'seen_custom_content_guide' => true,
            'source_system' => ConvoLabAccountSource::LEARNING_OS,
        ]);
    }

    public function test_sparse_profile_update_preserves_omitted_fields(): void
    {
        $account = $this->projectedUser([
            'display_name' => 'Original',
            'avatar_color' => 'rose',
            'avatar_url' => 'https://example.com/avatar.png',
            'proficiency_level' => 'N4',
            'onboarding_completed' => true,
            'seen_sample_content_guide' => true,
        ]);

        $this->withToken($this->proxyToken(['auth:write']))
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->patchJson('/api/convolab/auth/me', ['seenCustomContentGuide' => true])
            ->assertOk()
            ->assertJsonPath('displayName', 'Original')
            ->assertJsonPath('avatarColor', 'rose')
            ->assertJsonPath('avatarUrl', 'https://example.com/avatar.png')
            ->assertJsonPath('proficiencyLevel', 'N4')
            ->assertJsonPath('onboardingCompleted', true)
            ->assertJsonPath('seenSampleContentGuide', true)
            ->assertJsonPath('seenCustomContentGuide', true);
    }

    public function test_profile_update_rejects_empty_and_malformed_payloads(): void
    {
        $account = $this->projectedUser();
        $token = $this->proxyToken(['auth:write']);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->patchJson('/api/convolab/auth/me')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');

        foreach ([
            [['displayName' => ['Ada']], 'displayName'],
            [['avatarColor' => 'blue'], 'avatarColor'],
            [['avatarUrl' => ['https://example.com/avatar.png']], 'avatarUrl'],
            [['preferredStudyLanguage' => 'en'], 'preferredStudyLanguage'],
            [['preferredNativeLanguage' => 'ja'], 'preferredNativeLanguage'],
            [['proficiencyLevel' => 'beginner'], 'proficiencyLevel'],
            [['onboardingCompleted' => 'yes'], 'onboardingCompleted'],
            [['seenSampleContentGuide' => null], 'seenSampleContentGuide'],
            [['seenCustomContentGuide' => []], 'seenCustomContentGuide'],
        ] as [$payload, $field]) {
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
                ->patchJson('/api/convolab/auth/me', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_profile_update_rejects_missing_malformed_and_unknown_identity_headers(): void
    {
        $token = $this->proxyToken(['auth:write']);

        $this->withToken($token)
            ->patchJson('/api/convolab/auth/me', ['displayName' => 'Ada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('convolabUserId');
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->patchJson('/api/convolab/auth/me', ['displayName' => 'Ada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('convolabUserId');
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->patchJson('/api/convolab/auth/me', ['displayName' => 'Ada'])
            ->assertNotFound();
    }

    public function test_profile_updates_are_rate_limited_per_identity(): void
    {
        $account = $this->projectedUser();
        $token = $this->proxyToken(['auth:write']);

        foreach (range(1, 30) as $attempt) {
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
                ->patchJson('/api/convolab/auth/me', ['displayName' => 'Ada '.$attempt])
                ->assertOk();
        }

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', strtoupper($account['convolab_id']))
            ->patchJson('/api/convolab/auth/me', ['displayName' => 'Blocked'])
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $other = $this->projectedUser(['email' => 'other@example.com']);
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $other['convolab_id'])
            ->patchJson('/api/convolab/auth/me', ['displayName' => 'Available'])
            ->assertOk();
    }

    /** @param array<string, mixed> $attributes */
    private function projectedUser(array $attributes = []): array
    {
        $convoLabId = (string) Str::uuid();
        $projection = array_merge([
            'convolab_id' => $convoLabId,
            'email' => 'user@example.com',
            'name' => 'Source User',
            'display_name' => null,
            'avatar_color' => 'indigo',
            'avatar_url' => null,
            'role' => 'user',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'proficiency_level' => 'N5',
            'onboarding_completed' => false,
            'seen_sample_content_guide' => false,
            'seen_custom_content_guide' => false,
            'email_verified' => true,
            'email_verified_at' => '2026-07-20 09:00:00.123',
            'created_at' => '2026-07-20 10:00:00.123',
            'updated_at' => '2026-07-20 11:00:00.456',
            'source_system' => ConvoLabAccountSource::CONVOLAB,
        ], $attributes);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);
        $projection['user_id'] = $user->id;
        DB::table('admin_user_projections')->insert($projection);

        return $projection;
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $proxy = User::query()->where('email', 'proxy@example.com')->first()
            ?? User::factory()->create(['email' => 'proxy@example.com']);

        return $proxy->createToken('convolab-proxy', $abilities)->plainTextToken;
    }
}
