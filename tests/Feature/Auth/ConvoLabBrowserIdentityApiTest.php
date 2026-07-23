<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Contracts\ConvoLabGoogleOAuthClient;
use App\Domain\Auth\Data\ConvoLabGoogleProfile;
use App\Domain\Auth\Support\ConvoLabBrowserOAuthSession;
use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Jobs\SendConvoLabVerificationEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class ConvoLabBrowserIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'https://convo-lab.test';

    private const NODE_BCRYPT_HASH = '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6';

    private FakeConvoLabGoogleOAuthClient $google;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['convo-lab.test']);
        config()->set('session.driver', 'database');
        config()->set('session.cookie', 'learning_os_session');
        config()->set('session.secure', true);
        config()->set('services.convolab.client_url', self::FRONTEND_ORIGIN);

        $this->google = new FakeConvoLabGoogleOAuthClient;
        $this->app->instance(ConvoLabGoogleOAuthClient::class, $this->google);
    }

    public function test_google_start_clears_stale_pending_identity_and_uses_the_oauth_client(): void
    {
        $response = $this->withSession([
            ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT => (string) Str::uuid(),
        ])->get('/api/convolab/browser/auth/google');

        $response->assertRedirect('https://accounts.google.test/authorize');
        $response->assertSessionMissing(
            ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
        );
        $this->assertSame(1, $this->google->redirectCalls);
    }

    public function test_google_start_rate_limit_accumulates_for_the_anonymous_session(): void
    {
        $first = $this->get('/api/convolab/browser/auth/google')
            ->assertRedirect('https://accounts.google.test/authorize');
        $session = $first->getCookie('learning_os_session');
        $this->assertNotNull($session);
        $this->withCookie('learning_os_session', $session->getValue());

        for ($attempt = 2; $attempt <= 20; $attempt++) {
            $this->get('/api/convolab/browser/auth/google')->assertRedirect(
                'https://accounts.google.test/authorize',
            );
        }

        $this->get('/api/convolab/browser/auth/google')
            ->assertTooManyRequests();
    }

    public function test_google_callback_starts_a_browser_session_for_an_existing_account(): void
    {
        $account = $this->projectedUser([
            'email' => 'ada@example.com',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->google->profile = $this->profile(email: 'ADA@example.com');

        $response = $this->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/app/library')
            ->assertSessionMissing(
                ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
            );

        $session = $response->getCookie('learning_os_session');
        $this->assertNotNull($session);
        $this->assertDatabaseHas('sessions', [
            'id' => $session->getValue(),
            'user_id' => $account['user_id'],
        ]);
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $account['user_id'],
            'provider' => 'google',
            'provider_id' => 'google-subject-123',
        ]);
    }

    public function test_new_google_account_is_kept_anonymous_until_it_claims_an_invite(): void
    {
        $this->google->profile = $this->profile(email: 'new@example.com');
        $start = $this->get('/api/convolab/browser/auth/google')
            ->assertRedirect('https://accounts.google.test/authorize');
        $anonymousSession = $start->getCookie('learning_os_session');
        $this->assertNotNull($anonymousSession);

        $response = $this->withCookie(
            'learning_os_session',
            $anonymousSession->getValue(),
        )->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/claim-invite')
            ->assertSessionHas(ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT);

        $user = User::query()->where('email', 'new@example.com')->sole();
        $pendingSession = $response->getCookie('learning_os_session');
        $this->assertNotNull($pendingSession);
        $this->assertNotSame($anonymousSession->getValue(), $pendingSession->getValue());
        $this->assertGuest('web');
        $this->assertDatabaseMissing('sessions', [
            'id' => $anonymousSession->getValue(),
        ]);
        $this->assertDatabaseHas('sessions', [
            'id' => $pendingSession->getValue(),
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $user->id,
            'access_granted_at' => null,
        ]);
        $this->assertNull($response->getCookie('token'));
    }

    public function test_google_callback_failure_clears_pending_identity_and_redirects_safely(): void
    {
        $this->google->failure = new \RuntimeException('provider failed');

        $this->withSession([
            ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT => (string) Str::uuid(),
        ])->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/login?error=oauth_failed')
            ->assertSessionMissing(
                ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
            );

        $this->assertGuest('web');
        $this->assertDatabaseCount('convolab_oauth_identities', 0);
    }

    public function test_google_callback_surfaces_expected_identity_rejection_reason(): void
    {
        $this->google->profile = new ConvoLabGoogleProfile(
            providerId: 'unverified-subject',
            email: 'unverified@example.com',
            name: 'Unverified User',
            avatarUrl: null,
            emailVerified: false,
        );

        $this->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/login?error=unverified_email')
            ->assertSessionMissing(
                ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
            );

        $this->assertGuest('web');
        $this->assertDatabaseCount('convolab_oauth_identities', 0);
    }

    public function test_pending_google_account_can_claim_an_invite_and_start_a_session(): void
    {
        $account = $this->pendingGoogleAccount();
        $this->invite('WELCOME1');

        $response = $this->withPendingGoogleAccount($account['convolab_id'])
            ->postJson('/api/convolab/browser/auth/google/invite', [
                'inviteCode' => ' WELCOME1 ',
            ])
            ->assertOk()
            ->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('email', 'new@example.com')
            ->assertSessionMissing(
                ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
            );

        $session = $response->getCookie('learning_os_session');
        $this->assertNotNull($session);
        $this->assertDatabaseHas('sessions', [
            'id' => $session->getValue(),
            'user_id' => $account['user_id'],
        ]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'code' => 'WELCOME1',
            'used_by' => $account['user_id'],
            'convolab_used_by' => $account['convolab_id'],
            'source_system' => 'learning_os',
        ]);
        $this->assertNotNull(
            DB::table('convolab_oauth_identities')
                ->where('user_id', $account['user_id'])
                ->value('access_granted_at'),
        );
    }

    public function test_failed_invite_claim_preserves_pending_session_for_retry(): void
    {
        $account = $this->pendingGoogleAccount();

        $this->withPendingGoogleAccount($account['convolab_id'])
            ->postJson('/api/convolab/browser/auth/google/invite', [
                'inviteCode' => 'NOPE',
            ])
            ->assertBadRequest()
            ->assertJsonPath('reason', 'invalid_invite')
            ->assertSessionHas(
                ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
                fn (array $value): bool => $value['convolab_user_id']
                    === $account['convolab_id'],
            );

        $this->assertGuest('web');
    }

    public function test_invite_claim_rate_limit_uses_the_pending_session_not_a_spoofed_header(): void
    {
        $account = $this->pendingGoogleAccount();

        $first = $this->withPendingGoogleAccount($account['convolab_id'])
            ->withHeader('X-Convo-Lab-User-Id', 'spoofed-user-1')
            ->postJson('/api/convolab/browser/auth/google/invite', [
                'inviteCode' => 'NOPE',
            ])
            ->assertBadRequest();
        $session = $first->getCookie('learning_os_session');
        $this->assertNotNull($session);
        $this->withCredentials()
            ->withCookie('learning_os_session', $session->getValue());

        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->withHeader('X-Convo-Lab-User-Id', 'spoofed-user-'.$attempt)
                ->postJson('/api/convolab/browser/auth/google/invite', [
                    'inviteCode' => 'NOPE',
                ])
                ->assertBadRequest();
        }

        $this->withHeader('X-Convo-Lab-User-Id', 'spoofed-user-6')
            ->postJson('/api/convolab/browser/auth/google/invite', [
                'inviteCode' => 'NOPE',
            ])
            ->assertTooManyRequests();
    }

    public function test_invite_claim_requires_a_stateful_pending_oauth_session(): void
    {
        $this->postJson('/api/convolab/browser/auth/google/invite', [
            'inviteCode' => 'WELCOME1',
        ])->assertForbidden();

        $this->withStatefulHeaders()
            ->postJson('/api/convolab/browser/auth/google/invite', [
                'inviteCode' => 'WELCOME1',
            ])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Google sign-in has expired',
                'reason' => 'oauth_session_expired',
            ]);
    }

    public function test_pending_google_invite_session_expires_after_fifteen_minutes(): void
    {
        $account = $this->pendingGoogleAccount();

        $this->travel(
            ConvoLabBrowserOAuthSession::PENDING_MINUTES + 1,
        )->minutes(function () use ($account): void {
            $this->withPendingGoogleAccount(
                $account['convolab_id'],
                now()->subMinute()->getTimestamp(),
            )->postJson('/api/convolab/browser/auth/google/invite', [
                'inviteCode' => 'WELCOME1',
            ])->assertUnauthorized()
                ->assertJsonPath('reason', 'oauth_session_expired')
                ->assertSessionMissing(
                    ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT,
                );
        });
    }

    public function test_browser_verification_consumes_a_normalized_one_time_token(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $rawToken = str_repeat('a', 64);
        $this->verificationToken($account['user_id'], $rawToken);

        $this->statefulPost('/api/convolab/browser/auth/verification', [
            'token' => '  '.strtoupper($rawToken).'  ',
        ])->assertOk()
            ->assertExactJson([
                'message' => 'Email verified successfully',
                'email' => 'ada@example.com',
            ]);

        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $account['convolab_id'],
            'email_verified' => true,
        ]);
        $this->assertNotNull(User::query()->findOrFail($account['user_id'])->email_verified_at);
        $this->assertNotNull(
            DB::table('convolab_email_verification_tokens')->sole()->consumed_at,
        );
    }

    public function test_browser_verification_is_idempotent_but_rejects_invalid_tokens(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $rawToken = str_repeat('b', 64);
        $this->verificationToken($account['user_id'], $rawToken);

        $this->statefulPost('/api/convolab/browser/auth/verification', [
            'token' => $rawToken,
        ])->assertOk();
        $this->statefulPost('/api/convolab/browser/auth/verification', [
            'token' => $rawToken,
        ])->assertOk();
        $this->statefulPost('/api/convolab/browser/auth/verification', [
            'token' => str_repeat('c', 64),
        ])->assertBadRequest()
            ->assertExactJson(['message' => 'Invalid or expired verification token']);
    }

    public function test_browser_verification_requires_stateful_csrf_context_and_valid_shape(): void
    {
        $this->postJson('/api/convolab/browser/auth/verification', [
            'token' => str_repeat('a', 64),
        ])->assertForbidden();

        $this->statefulPost('/api/convolab/browser/auth/verification', [
            'token' => '../not-a-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_authenticated_browser_can_resend_for_itself_without_proxy_identity(): void
    {
        Queue::fake();
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $other = $this->projectedUser(['email' => 'grace@example.com']);
        $user = User::query()->findOrFail($account['user_id']);

        $this->withStatefulHeaders()
            ->withSession([])
            ->actingAs($user, 'web')
            ->withHeader('X-Convo-Lab-User-Id', $other['convolab_id'])
            ->postJson('/api/convolab/browser/auth/verification/send')
            ->assertOk()
            ->assertExactJson(['message' => 'Verification email sent']);

        Queue::assertPushed(
            SendConvoLabVerificationEmail::class,
            fn (SendConvoLabVerificationEmail $job): bool => $job->userId === $user->id,
        );
        Queue::assertNotPushed(
            SendConvoLabVerificationEmail::class,
            fn (SendConvoLabVerificationEmail $job): bool => $job->userId === $other['user_id'],
        );
    }

    public function test_browser_resend_rejects_guests_and_verified_accounts(): void
    {
        $this->statefulPost('/api/convolab/browser/auth/verification/send')
            ->assertUnauthorized();

        $account = $this->projectedUser([
            'email' => 'verified@example.com',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $user = User::query()->findOrFail($account['user_id']);

        $this->withStatefulHeaders()
            ->withSession([])
            ->actingAs($user, 'web')
            ->postJson('/api/convolab/browser/auth/verification/send')
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Email is already verified']);
    }

    public function test_browser_identity_routes_reuse_named_rate_limits_and_web_oauth_state(): void
    {
        $postRoutes = collect(app('router')->getRoutes()->getRoutesByMethod()['POST']);
        $getRoutes = collect(app('router')->getRoutes()->getRoutesByMethod()['GET']);

        $this->assertContains(
            'throttle:convolab-verification-verify',
            $postRoutes->first(
                fn ($route) => $route->uri() === 'api/convolab/browser/auth/verification',
            )->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:convolab-verification-send',
            $postRoutes->first(
                fn ($route) => $route->uri() === 'api/convolab/browser/auth/verification/send',
            )->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ConvoLabOAuthRateLimiter::BROWSER_CLAIM,
            $postRoutes->first(
                fn ($route) => $route->uri() === 'api/convolab/browser/auth/google/invite',
            )->gatherMiddleware(),
        );

        foreach (['api/convolab/browser/auth/google', 'api/convolab/browser/auth/google/callback'] as $uri) {
            $middleware = $getRoutes->first(fn ($route) => $route->uri() === $uri)
                ->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains(
                'throttle:'.(
                    str_ends_with($uri, '/callback')
                        ? ConvoLabOAuthRateLimiter::BROWSER_CALLBACK
                        : ConvoLabOAuthRateLimiter::BROWSER_START
                ),
                $middleware,
            );
        }
    }

    private function withPendingGoogleAccount(
        string $convoLabUserId,
        ?int $expiresAt = null,
    ): static {
        return $this->withStatefulHeaders()->withSession([
            ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT => [
                'convolab_user_id' => $convoLabUserId,
                'expires_at' => $expiresAt
                    ?? now()->addMinutes(
                        ConvoLabBrowserOAuthSession::PENDING_MINUTES,
                    )->getTimestamp(),
            ],
        ]);
    }

    private function statefulPost(string $path, array $payload = [])
    {
        return $this->withStatefulHeaders()
            ->withSession([])
            ->postJson($path, $payload);
    }

    private function withStatefulHeaders(): static
    {
        return $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/');
    }

    private function verificationToken(int $userId, string $rawToken): void
    {
        DB::table('convolab_email_verification_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDay(),
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function pendingGoogleAccount(): array
    {
        $account = $this->projectedUser([
            'email' => 'new@example.com',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        DB::table('convolab_oauth_identities')->insert([
            'user_id' => $account['user_id'],
            'provider' => 'google',
            'provider_id' => 'google-subject-123',
            'access_granted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $account;
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
            'email_verified' => false,
            'email_verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'source_system' => 'learning_os',
            'avatar_source_system' => 'learning_os',
        ], $attributes);
        $user = User::factory()->create(['email' => strtolower($projection['email'])]);
        DB::table('users')->where('id', $user->id)->update([
            'convolab_id' => $convoLabId,
            'convolab_email_normalized' => strtolower(trim($projection['email'])),
            'convolab_password_hash' => self::NODE_BCRYPT_HASH,
        ]);
        $projection['user_id'] = $user->id;
        DB::table('admin_user_projections')->insert($projection);

        return $projection;
    }

    private function invite(string $code): void
    {
        DB::table('admin_invite_codes')->insert([
            'id' => (string) Str::uuid(),
            'code' => $code,
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => now(),
            'source_system' => 'convolab',
        ]);
    }

    private function profile(string $email): ConvoLabGoogleProfile
    {
        return new ConvoLabGoogleProfile(
            providerId: 'google-subject-123',
            email: $email,
            name: 'Ada Lovelace',
            avatarUrl: 'https://example.com/ada.png',
            emailVerified: true,
        );
    }
}

final class FakeConvoLabGoogleOAuthClient implements ConvoLabGoogleOAuthClient
{
    public int $redirectCalls = 0;

    public ?ConvoLabGoogleProfile $profile = null;

    public ?\Throwable $failure = null;

    public function redirect(): RedirectResponse
    {
        $this->redirectCalls++;

        return new RedirectResponse('https://accounts.google.test/authorize');
    }

    public function user(): ConvoLabGoogleProfile
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->profile ?? throw new \LogicException('Missing fake Google profile.');
    }
}
