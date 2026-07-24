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

    public function test_profile_update_requires_a_first_party_browser_session(): void
    {
        $account = $this->projectedUser();
        $path = '/api/convolab/auth/me';

        $this->patchJson($path, ['displayName' => 'Ada'])
            ->assertUnauthorized();

        $ordinaryToken = User::factory()->create()
            ->createToken('mobile', ['auth:write'])
            ->plainTextToken;
        $this->withToken($ordinaryToken)
            ->patchJson($path, ['displayName' => 'Ada'])
            ->assertForbidden();

        $this->asConvoLabBrowser(User::factory()->create())
            ->patchJson($path, ['displayName' => 'Ada'])
            ->assertForbidden();
    }

    public function test_profile_update_normalizes_the_identity_and_returns_the_exact_legacy_shape(): void
    {
        $account = $this->projectedUser([
            'avatar_url' => 'https://example.com/old.png',
            'seen_sample_content_guide' => true,
        ]);

        $response = $this->authenticate($account)
            ->withoutMiddleware(TrimStrings::class)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
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

        $this->authenticate($account)
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

    public function test_laravel_accepted_boolean_completion_values_are_normalized(): void
    {
        foreach ([1, '1'] as $index => $value) {
            $account = $this->projectedUser(['email' => 'boolean-'.$index.'@example.com']);

            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->authenticate($account)
                ->patchJson('/api/convolab/auth/me', [
                    'proficiencyLevel' => 'N5',
                    'onboardingCompleted' => $value,
                ])
                ->assertOk()
                ->assertJsonPath('onboardingCompleted', true);

            $this->assertDatabaseHas('admin_user_projections', [
                'convolab_id' => $account['convolab_id'],
                'onboarding_completed' => true,
            ]);
        }
    }

    public function test_profile_update_rejects_empty_and_malformed_payloads(): void
    {
        $account = $this->projectedUser();
        $this->authenticate($account);

        $this
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
            [['onboardingCompleted' => true], 'proficiencyLevel'],
            [['onboardingCompleted' => 'yes'], 'onboardingCompleted'],
            [['seenSampleContentGuide' => null], 'seenSampleContentGuide'],
            [['seenCustomContentGuide' => []], 'seenCustomContentGuide'],
        ] as [$payload, $field]) {
            $this
                ->patchJson('/api/convolab/auth/me', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_profile_updates_are_rate_limited_per_identity(): void
    {
        $account = $this->projectedUser();
        $this->authenticate($account);

        foreach (range(1, 30) as $attempt) {
            $this
                ->patchJson('/api/convolab/auth/me', ['displayName' => 'Ada '.$attempt])
                ->assertOk();
        }

        $this
            ->patchJson('/api/convolab/auth/me', ['displayName' => 'Blocked'])
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $other = $this->projectedUser(['email' => 'other@example.com']);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->authenticate($other)
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

    /** @param array<string, mixed> $account */
    private function authenticate(array $account): static
    {
        return $this->asConvoLabBrowser(
            User::query()->findOrFail($account['user_id']),
            convoLabUserId: $account['convolab_id'],
        );
    }
}
