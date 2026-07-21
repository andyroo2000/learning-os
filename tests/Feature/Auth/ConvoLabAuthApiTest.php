<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\AuthenticateConvoLabUserAction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const NODE_BCRYPT_HASH = '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

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

    public function test_compatibility_auth_requires_the_named_proxy_identity_and_exact_scopes(): void
    {
        $this->postJson('/api/convolab/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertUnauthorized();

        $ordinaryToken = User::factory()->create()
            ->createToken('mobile', ['auth:login', 'auth:read'])
            ->plainTextToken;
        $this->withToken($ordinaryToken)
            ->postJson('/api/convolab/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'password',
            ])
            ->assertForbidden();

        $wildcardToken = User::factory()->create(['email' => 'wildcard@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
        $this->withToken($wildcardToken)
            ->postJson('/api/convolab/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'password',
            ])
            ->assertForbidden();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        $this->withToken($this->proxyToken(['auth:read']))
            ->postJson('/api/convolab/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'password',
            ])
            ->assertForbidden();

        $account = $this->projectedUser();
        $this->withToken($this->proxyToken(['auth:login']))
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->getJson('/api/convolab/auth/me')
            ->assertForbidden();
    }

    public function test_login_accepts_a_node_bcrypt_hash_and_returns_the_exact_legacy_account_shape(): void
    {
        $account = $this->projectedUser([
            'email' => 'Ada@Example.com',
            'name' => 'Ada Lovelace',
            'display_name' => 'Ada',
            'avatar_color' => 'teal',
            'avatar_url' => 'https://example.com/ada.png',
            'role' => 'admin',
            'proficiency_level' => 'N3',
            'onboarding_completed' => true,
            'seen_sample_content_guide' => true,
            'seen_custom_content_guide' => true,
            'email_verified' => true,
            'email_verified_at' => '2026-07-20 09:00:00.123',
        ]);

        $this->withoutMiddleware(TrimStrings::class)
            ->withToken($this->proxyToken(['auth:login']))
            ->postJson('/api/convolab/auth/login', [
                'email' => " \tADA@EXAMPLE.COM\n ",
                'password' => 'correct horse battery staple',
            ])
            ->assertOk()
            ->assertExactJson($this->expectedLoginAccount($account));
    }

    public function test_login_returns_one_generic_failure_for_wrong_unknown_and_oauth_only_accounts(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);
        $this->projectedUser(
            ['email' => 'oauth@example.com'],
            passwordHash: null,
        );
        $token = $this->proxyToken(['auth:login']);

        foreach ([
            ['email' => 'ada@example.com', 'password' => 'wrong'],
            ['email' => 'unknown@example.com', 'password' => 'wrong'],
            ['email' => 'oauth@example.com', 'password' => 'wrong'],
        ] as $credentials) {
            $this->withToken($token)
                ->postJson('/api/convolab/auth/login', $credentials)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Invalid credentials.']);
        }
    }

    public function test_login_validates_bounded_scalar_credentials(): void
    {
        $token = $this->proxyToken(['auth:login']);

        foreach ([
            [['email' => ['ada@example.com'], 'password' => 'password'], 'email'],
            [['email' => 'not-an-email', 'password' => 'password'], 'email'],
            [['email' => 'ada@example.com', 'password' => ['password']], 'password'],
            [['email' => 'ada@example.com', 'password' => str_repeat('a', 1025)], 'password'],
        ] as [$payload, $field]) {
            $this->withToken($token)
                ->postJson('/api/convolab/auth/login', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_login_is_rate_limited_by_normalized_email_and_network(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);
        $token = $this->proxyToken(['auth:login']);

        foreach (range(1, 6) as $attempt) {
            $this->withToken($token)
                ->postJson('/api/convolab/auth/login', [
                    'email' => $attempt % 2 === 0 ? ' ADA@example.com ' : 'ada@example.com',
                    'password' => 'wrong',
                ])
                ->assertUnauthorized();
        }

        config()->set('app.debug', false);
        $response = $this->withToken($token)
            ->postJson('/api/convolab/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong',
            ])
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('X-RateLimit-Limit', '6')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertIsNumeric($retryAfter);
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);
    }

    public function test_current_user_resolves_a_normalized_header_and_hides_unknown_owners(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $token = $this->proxyToken(['auth:read']);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', '  '.strtoupper($account['convolab_id']).'  ')
            ->getJson('/api/convolab/auth/me')
            ->assertOk()
            ->assertExactJson([
                ...$this->expectedLoginAccount($account),
                'seenSampleContentGuide' => $account['seen_sample_content_guide'],
                'seenCustomContentGuide' => $account['seen_custom_content_guide'],
            ]);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->getJson('/api/convolab/auth/me')
            ->assertNotFound();
    }

    public function test_current_user_rejects_missing_and_malformed_identity_headers(): void
    {
        $token = $this->proxyToken(['auth:read']);

        $this->withToken($token)
            ->getJson('/api/convolab/auth/me')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('convolabUserId');
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->getJson('/api/convolab/auth/me')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('convolabUserId');
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

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $proxy = User::query()->where('email', 'proxy@example.com')->first()
            ?? User::factory()->create(['email' => 'proxy@example.com']);

        return $proxy
            ->createToken('convolab-proxy', $abilities)
            ->plainTextToken;
    }
}
