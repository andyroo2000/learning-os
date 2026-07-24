<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\AuthenticateConvoLabUserAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const NODE_BCRYPT_HASH = '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6';

    public function test_auth_projection_schema_is_additive_and_keeps_imported_credentials_hidden(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'convolab_email_normalized',
            'convolab_password_hash',
        ]));
        $this->assertTrue(Schema::hasColumns('admin_user_projections', [
            'proficiency_level',
            'seen_sample_content_guide',
            'seen_custom_content_guide',
            'email_verified',
            'email_verified_at',
        ]));

        $account = $this->projectedUser();
        $user = User::query()->where('convolab_id', $account['convolab_id'])->sole();

        $this->assertArrayNotHasKey('convolab_password_hash', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('convolab_email_normalized', $user->fresh()->toArray());
        $this->assertFalse($user->isFillable('convolab_password_hash'));
        $this->assertFalse($user->isFillable('convolab_email_normalized'));

        $matchingIndexes = collect(Schema::getIndexes('users'))
            ->where('name', 'users_convolab_email_normalized_unique');
        $this->assertCount(1, $matchingIndexes);
        $this->assertTrue($matchingIndexes->first()['unique']);

        $projection = app(AuthenticateConvoLabUserAction::class)->handle(
            'user@example.com',
            'correct horse battery staple',
        );
        $this->assertArrayNotHasKey('convolab_password_hash', $projection->toArray());
    }

    public function test_retired_proxy_login_route_is_not_exposed(): void
    {
        $this->postJson('/api/convolab/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertNotFound();
    }

    public function test_current_user_resolves_the_authenticated_browser_session(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $user = User::query()->findOrFail($account['user_id']);

        $this->asConvoLabBrowser($user, convoLabUserId: strtoupper($account['convolab_id']))
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->getJson('/api/convolab/auth/me')
            ->assertOk()
            ->assertExactJson([
                ...$this->expectedLoginAccount($account),
                'seenSampleContentGuide' => $account['seen_sample_content_guide'],
                'seenCustomContentGuide' => $account['seen_custom_content_guide'],
            ]);
    }

    public function test_current_user_rejects_api_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['auth:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/convolab/auth/me')
            ->assertForbidden();
    }

    public function test_login_uses_one_database_query_for_the_account_and_credential(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(AuthenticateConvoLabUserAction::class)->handle(
                'ada@example.com',
                'correct horse battery staple',
            );
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $queries);
    }

    /** @param array<string, mixed> $attributes */
    private function projectedUser(
        array $attributes = [],
        ?string $passwordHash = self::NODE_BCRYPT_HASH,
    ): array {
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
            'proficiency_level' => 'beginner',
            'onboarding_completed' => false,
            'seen_sample_content_guide' => false,
            'seen_custom_content_guide' => false,
            'email_verified' => false,
            'email_verified_at' => null,
            'created_at' => '2026-07-20 10:00:00.123',
            'updated_at' => '2026-07-20 11:00:00.456',
        ], $attributes);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'convolab_id' => $convoLabId,
            'convolab_email_normalized' => strtolower(trim($projection['email'])),
            'convolab_password_hash' => $passwordHash,
        ]);
        $projection['user_id'] = $user->id;
        DB::table('admin_user_projections')->insert($projection);

        return $projection;
    }

    /** @param array<string, mixed> $account */
    private function expectedLoginAccount(array $account): array
    {
        return [
            'id' => $account['convolab_id'],
            'email' => $account['email'],
            'name' => $account['name'],
            'displayName' => $account['display_name'],
            'avatarColor' => $account['avatar_color'],
            'avatarUrl' => $account['avatar_url'],
            'role' => $account['role'],
            'preferredStudyLanguage' => $account['preferred_study_language'],
            'preferredNativeLanguage' => $account['preferred_native_language'],
            'proficiencyLevel' => $account['proficiency_level'],
            'onboardingCompleted' => $account['onboarding_completed'],
            'emailVerified' => $account['email_verified'],
            'emailVerifiedAt' => $account['email_verified_at'] === null
                ? null
                : '2026-07-20T09:00:00.123Z',
            'createdAt' => '2026-07-20T10:00:00.123Z',
            'updatedAt' => '2026-07-20T11:00:00.456Z',
        ];
    }
}
