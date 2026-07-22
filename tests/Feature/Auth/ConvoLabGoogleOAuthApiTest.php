<?php

namespace Tests\Feature\Auth;

use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Auth\Actions\ClaimConvoLabGoogleInviteAction;
use App\Domain\Auth\Actions\DisconnectConvoLabGoogleIdentityAction;
use App\Domain\Auth\Actions\ResolveConvoLabGoogleIdentityAction;
use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabGoogleOAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        Carbon::setTestNow('2026-07-23 00:15:30.123 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_oauth_identity_schema_stores_no_google_credentials_and_uses_portable_named_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('convolab_oauth_identities', [
            'id',
            'user_id',
            'provider',
            'provider_id',
            'access_granted_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('convolab_oauth_identities', 'access_token'));
        $this->assertFalse(Schema::hasColumn('convolab_oauth_identities', 'refresh_token'));

        $indexes = collect(Schema::getIndexes('convolab_oauth_identities'));
        $this->assertTrue($indexes->firstWhere('name', 'convolab_oauth_provider_identity_unique')['unique']);
        $this->assertTrue($indexes->firstWhere('name', 'convolab_oauth_user_provider_unique')['unique']);
    }

    public function test_oauth_routes_require_the_named_proxy_and_exact_oauth_scope(): void
    {
        $payload = $this->googlePayload();

        $this->postJson('/api/convolab/auth/google', $payload)->assertUnauthorized();

        $ordinary = User::factory()->create()
            ->createToken('mobile', ['auth:oauth'])
            ->plainTextToken;
        $this->withToken($ordinary)
            ->postJson('/api/convolab/auth/google', $payload)
            ->assertForbidden();

        $wildcard = User::factory()->create(['email' => 'wildcard@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
        $this->withToken($wildcard)
            ->postJson('/api/convolab/auth/google', $payload)
            ->assertForbidden();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        $wrongScope = $this->proxyToken(['auth:login']);
        $this->withToken($wrongScope)
            ->postJson('/api/convolab/auth/google', $payload)
            ->assertForbidden();
    }

    public function test_new_google_identity_creates_a_verified_oauth_only_account_that_still_requires_an_invite(): void
    {
        $response = $this->withoutMiddleware(TrimStrings::class)
            ->withToken($this->proxyToken())
            ->postJson('/api/convolab/auth/google', $this->googlePayload([
                'providerId' => '  google-subject-123  ',
                'email' => " \tADA@EXAMPLE.COM\n ",
                'name' => '  Ada Lovelace  ',
                'avatarUrl' => '  https://example.com/ada.png  ',
            ]))
            ->assertOk()
            ->assertJsonPath('requiresInvite', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.name', 'Ada Lovelace')
            ->assertJsonPath('user.avatarUrl', 'https://example.com/ada.png')
            ->assertJsonPath('user.emailVerified', true)
            ->assertJsonPath('user.emailVerifiedAt', '2026-07-23T00:15:30.000Z');

        $convoLabId = $response->json('user.id');
        $this->assertTrue(Str::isUuid($convoLabId));
        $user = User::query()->where('convolab_id', $convoLabId)->sole();
        $this->assertSame('ada@example.com', $user->convolab_email_normalized);
        $this->assertNull($user->convolab_password_hash);
        $this->assertNotNull($user->password);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $convoLabId,
            'source_system' => 'learning_os',
            'avatar_source_system' => 'learning_os',
            'email_verified' => true,
        ]);
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-subject-123',
            'access_granted_at' => null,
        ]);
    }

    public function test_unclaimed_identity_retries_remain_invite_gated_and_do_not_duplicate_accounts(): void
    {
        $action = app(ResolveConvoLabGoogleIdentityAction::class);
        $first = $action->handle('subject-1', 'new@example.com', 'First Name', null);
        $second = $action->handle(
            'subject-1',
            'changed@example.com',
            'Changed Name',
            'https://example.com/changed.png',
        );

        $this->assertTrue($first->created);
        $this->assertTrue($first->requiresInvite);
        $this->assertFalse($second->created);
        $this->assertTrue($second->requiresInvite);
        $this->assertSame($first->account->convolab_id, $second->account->convolab_id);
        $this->assertSame('new@example.com', $second->account->email);
        $this->assertDatabaseCount('convolab_oauth_identities', 1);
        $this->assertDatabaseCount('admin_user_projections', 1);
    }

    public function test_existing_projected_email_is_linked_with_immediate_access_and_verified(): void
    {
        $account = $this->projectedUser([
            'email' => 'existing@example.com',
            'email_verified' => false,
            'email_verified_at' => null,
        ]);

        $response = $this->withToken($this->proxyToken())
            ->postJson('/api/convolab/auth/google', $this->googlePayload([
                'email' => 'EXISTING@example.com',
                'name' => 'Google Name',
            ]))
            ->assertOk()
            ->assertJsonPath('requiresInvite', false)
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $account['convolab_id'])
            ->assertJsonPath('user.name', 'Source User')
            ->assertJsonPath('user.emailVerified', true);

        $this->assertNotNull($response->json('user.emailVerifiedAt'));
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $account['user_id'],
            'provider_id' => 'google-subject-123',
            'access_granted_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    public function test_existing_canonical_user_becomes_a_new_invite_gated_convolab_account_without_losing_password(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile@example.com',
            'password' => 'existing-password',
        ]);
        $originalHash = $user->password;

        $result = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'mobile-subject',
            'mobile@example.com',
            'Mobile User',
            null,
        );

        $this->assertTrue($result->created);
        $this->assertTrue($result->requiresInvite);
        $this->assertSame($user->id, $result->account->user_id);
        $this->assertSame($originalHash, $user->refresh()->password);
        $this->assertTrue(Hash::check('existing-password', $user->password));
    }

    public function test_a_different_google_subject_cannot_take_over_an_already_linked_email(): void
    {
        $account = $this->projectedUser(['email' => 'linked@example.com']);
        app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'original-subject',
            'linked@example.com',
            'Linked',
            null,
        );

        $this->withToken($this->proxyToken())
            ->postJson('/api/convolab/auth/google', $this->googlePayload([
                'providerId' => 'different-subject',
                'email' => 'linked@example.com',
            ]))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'A different Google account is already connected',
                'reason' => 'identity_already_connected',
            ]);

        $this->assertDatabaseCount('convolab_oauth_identities', 1);
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $account['user_id'],
            'provider_id' => 'original-subject',
        ]);
    }

    public function test_an_orphaned_identity_is_hidden_as_not_found_instead_of_leaking_an_invariant_error(): void
    {
        $user = User::factory()->create();
        DB::table('convolab_oauth_identities')->insert([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'orphaned-subject',
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($this->proxyToken())
            ->postJson('/api/convolab/auth/google', $this->googlePayload([
                'providerId' => 'orphaned-subject',
            ]))
            ->assertNotFound();
    }

    public function test_claim_invite_is_locked_retry_safe_and_grants_future_google_logins(): void
    {
        $result = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'claim-subject',
            'claim@example.com',
            'Claim User',
            null,
        );
        $invite = $this->invite('WELCOME123');
        $token = $this->proxyToken();

        $this->withoutMiddleware(TrimStrings::class)
            ->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', '  '.strtoupper($result->account->convolab_id).'  ')
            ->postJson('/api/convolab/auth/google/invite', ['inviteCode' => '  WELCOME123  '])
            ->assertOk()
            ->assertJsonPath('id', $result->account->convolab_id)
            ->assertJsonPath('seenSampleContentGuide', false)
            ->assertJsonPath('seenCustomContentGuide', false)
            ->assertJsonStructure([
                'id',
                'email',
                'name',
                'displayName',
                'avatarColor',
                'avatarUrl',
                'role',
                'preferredStudyLanguage',
                'preferredNativeLanguage',
                'proficiencyLevel',
                'onboardingCompleted',
                'emailVerified',
                'emailVerifiedAt',
                'createdAt',
                'updatedAt',
                'seenSampleContentGuide',
                'seenCustomContentGuide',
            ]);

        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $invite->id,
            'used_by' => $result->account->user_id,
            'convolab_used_by' => $result->account->convolab_id,
            'source_system' => 'learning_os',
        ]);
        $this->assertDatabaseMissing('convolab_oauth_identities', [
            'user_id' => $result->account->user_id,
            'access_granted_at' => null,
        ]);

        // A transport retry for the same account and code is idempotent.
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $result->account->convolab_id)
            ->postJson('/api/convolab/auth/google/invite', ['inviteCode' => 'WELCOME123'])
            ->assertOk();

        $retry = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'claim-subject',
            'claim@example.com',
            'Claim User',
            null,
        );
        $this->assertFalse($retry->requiresInvite);
        $this->assertDatabaseCount('admin_invite_codes', 1);
    }

    public function test_invalid_used_and_second_invite_claims_do_not_consume_another_code(): void
    {
        $first = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'first-subject',
            'first@example.com',
            'First',
            null,
        );
        $second = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'second-subject',
            'second@example.com',
            'Second',
            null,
        );
        $used = $this->invite('USED123', $first->account->user_id, $first->account->convolab_id);
        $available = $this->invite('AVAILABLE123');

        $token = $this->proxyToken();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $second->account->convolab_id)
            ->postJson('/api/convolab/auth/google/invite', ['inviteCode' => 'MISSING'])
            ->assertBadRequest()
            ->assertJsonPath('reason', 'invalid_invite');
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $second->account->convolab_id)
            ->postJson('/api/convolab/auth/google/invite', ['inviteCode' => 'USED123'])
            ->assertBadRequest()
            ->assertJsonPath('reason', 'used_invite');

        app(ClaimConvoLabGoogleInviteAction::class)->handle(
            $second->account->convolab_id,
            'AVAILABLE123',
        );
        $another = $this->invite('ANOTHER123');
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $second->account->convolab_id)
            ->postJson('/api/convolab/auth/google/invite', ['inviteCode' => 'ANOTHER123'])
            ->assertConflict()
            ->assertJsonPath('reason', 'invite_already_claimed');

        $this->assertSame($second->account->user_id, $available->refresh()->used_by);
        $this->assertNull($another->refresh()->used_by);
        $this->assertSame($first->account->user_id, $used->refresh()->used_by);
    }

    public function test_disconnect_removes_only_the_google_identity_and_returns_a_stable_missing_error(): void
    {
        $account = $this->projectedUser(['email' => 'linked@example.com']);
        app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'linked-subject',
            'linked@example.com',
            'Linked',
            null,
        );
        $token = $this->proxyToken();

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->deleteJson('/api/convolab/auth/google')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Google account disconnected',
            ]);

        $this->assertDatabaseMissing('convolab_oauth_identities', ['user_id' => $account['user_id']]);
        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $account['convolab_id']]);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $account['convolab_id'])
            ->deleteJson('/api/convolab/auth/google')
            ->assertNotFound()
            ->assertJsonPath('reason', 'identity_not_found');
    }

    public function test_oauth_requests_validate_bounded_scalar_inputs_and_normalized_identity_headers(): void
    {
        $token = $this->proxyToken();
        foreach ([
            [['providerId' => [], 'email' => 'a@example.com', 'name' => 'A'], 'providerId'],
            [['providerId' => 'id', 'email' => 'not-email', 'name' => 'A'], 'email'],
            [['providerId' => 'id', 'email' => 'a@example.com', 'name' => []], 'name'],
            [['providerId' => 'id', 'email' => 'a@example.com', 'name' => 'A', 'avatarUrl' => 'ftp://bad'], 'avatarUrl'],
        ] as [$payload, $field]) {
            $this->withToken($token)
                ->postJson('/api/convolab/auth/google', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->withToken($token)
            ->postJson('/api/convolab/auth/google/invite', ['inviteCode' => 'WELCOME'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('convolabUserId');
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->deleteJson('/api/convolab/auth/google')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('convolabUserId');
    }

    public function test_oauth_routes_wire_separate_named_rate_limiters(): void
    {
        foreach ([
            ['POST', 'api/convolab/auth/google', ConvoLabOAuthRateLimiter::RESOLVE],
            ['POST', 'api/convolab/auth/google/invite', ConvoLabOAuthRateLimiter::CLAIM],
            ['DELETE', 'api/convolab/auth/google', ConvoLabOAuthRateLimiter::DISCONNECT],
        ] as [$method, $uri, $limiter]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route): bool => in_array($method, $route->methods(), true) && $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
    }

    public function test_google_identity_resolution_returns_the_default_throttle_contract(): void
    {
        $token = $this->proxyToken();
        $payload = $this->googlePayload([
            'providerId' => 'rate-limit-subject',
            'email' => 'rate-limit@example.com',
        ]);

        foreach (range(1, 12) as $_attempt) {
            $this->withToken($token)
                ->postJson('/api/convolab/auth/google', $payload)
                ->assertOk();
        }

        config()->set('app.debug', false);
        $this->withToken($token)
            ->postJson('/api/convolab/auth/google', $payload)
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('X-RateLimit-Limit', '12')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
    }

    public function test_direct_actions_hide_malformed_and_unknown_account_ids(): void
    {
        foreach (['not-a-uuid', (string) Str::uuid()] as $id) {
            try {
                app(DisconnectConvoLabGoogleIdentityAction::class)->handle($id);
                $this->fail('Expected a hidden not-found response.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('convolab_oauth_identities', 0);
            }

            try {
                app(ClaimConvoLabGoogleInviteAction::class)->handle($id, 'WELCOME');
                $this->fail('Expected a hidden not-found response.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('admin_invite_codes', 0);
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function googlePayload(array $overrides = []): array
    {
        return array_merge([
            'providerId' => 'google-subject-123',
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'avatarUrl' => null,
        ], $overrides);
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities = ['auth:oauth']): string
    {
        $proxy = User::query()->where('email', 'proxy@example.com')->first()
            ?? User::factory()->create(['email' => 'proxy@example.com']);

        return $proxy->createToken('convolab-proxy', $abilities)->plainTextToken;
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
            'proficiency_level' => 'beginner',
            'onboarding_completed' => false,
            'seen_sample_content_guide' => false,
            'seen_custom_content_guide' => false,
            'email_verified' => true,
            'email_verified_at' => '2026-07-22 22:00:00.000',
            'created_at' => '2026-07-22 22:00:00.000',
            'updated_at' => '2026-07-22 22:00:00.000',
            'source_system' => 'convolab',
            'avatar_source_system' => 'convolab',
        ], $attributes);
        $user = User::factory()->create([
            'email' => strtolower($projection['email']),
            'email_verified_at' => $projection['email_verified_at'],
        ]);
        $user->forceFill([
            'convolab_id' => $convoLabId,
            'convolab_email_normalized' => strtolower($projection['email']),
        ])->save();
        $projection['user_id'] = $user->id;
        DB::table('admin_user_projections')->insert($projection);

        return $projection;
    }

    private function invite(
        string $code,
        ?int $usedBy = null,
        ?string $convoLabUsedBy = null,
    ): AdminInviteCode {
        $invite = new AdminInviteCode;
        $invite->id = (string) Str::uuid();
        $invite->code = $code;
        $invite->used_by = $usedBy;
        $invite->convolab_used_by = $convoLabUsedBy;
        $invite->used_at = $usedBy === null ? null : now();
        $invite->created_at = now();
        $invite->source_system = 'convolab';
        $invite->save();

        return $invite;
    }
}
