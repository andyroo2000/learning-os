<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendConvoLabVerificationEmail;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class ConvoLabBrowserSessionApiTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'https://convo-lab.test';

    private const NODE_BCRYPT_HASH = '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['convo-lab.test']);
        config()->set('session.driver', 'database');
        config()->set('session.cookie', 'learning_os_session');
        config()->set('session.secure', true);
    }

    public function test_stateful_login_rotates_the_session_and_returns_the_compatibility_account(): void
    {
        $account = $this->projectedUser([
            'email' => 'Ada@Example.com',
            'display_name' => 'Ada',
            'role' => 'admin',
            'email_verified' => true,
            'email_verified_at' => '2026-07-20 09:00:00.123',
        ]);
        $csrf = $this->csrfSession();

        $response = $this->withoutMiddleware(TrimStrings::class)->statefulJson(
            'POST',
            '/api/convolab/browser/auth/login',
            ['email' => ' ADA@example.com ', 'password' => 'correct horse battery staple'],
            $csrf,
        )->assertOk()
            ->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('email', 'Ada@Example.com')
            ->assertJsonPath('displayName', 'Ada')
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('emailVerified', true);

        $authenticatedSession = $this->requiredCookie($response, 'learning_os_session');

        $this->assertNotSame($csrf['session'], $authenticatedSession->getValue());
        $this->assertDatabaseHas('sessions', [
            'id' => $authenticatedSession->getValue(),
            'user_id' => $account['user_id'],
        ]);
        $this->assertDatabaseMissing('sessions', [
            'id' => $csrf['session'],
        ]);
    }

    public function test_browser_login_requires_a_stateful_session(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);

        $this->postJson('/api/convolab/browser/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
        ])->assertForbidden();
    }

    public function test_stateful_api_uses_csrf_middleware_that_rejects_a_missing_token(): void
    {
        $this->assertSame(
            ValidateCsrfToken::class,
            config('sanctum.middleware.validate_csrf_token'),
        );

        $request = Request::create('/api/convolab/browser/auth/login', 'POST');
        $session = new Store('browser-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response()->noContent());
    }

    public function test_browser_auth_preflights_expose_configured_origins_with_credentials(): void
    {
        config()->set('cors.allowed_origins', [self::FRONTEND_ORIGIN]);

        $this->withHeaders([
            'Origin' => self::FRONTEND_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/convolab/browser/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_browser_auth_preflights_never_reflect_untrusted_origins(): void
    {
        config()->set('cors.allowed_origins', [self::FRONTEND_ORIGIN]);

        $untrusted = $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/convolab/browser/auth/login')->assertNoContent();

        $untrusted->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN);
        $this->assertNotSame(
            'https://untrusted.example',
            $untrusted->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function test_browser_login_rejects_invalid_credentials_without_authenticating_the_session(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);
        $csrf = $this->csrfSession();

        $this->statefulJson(
            'POST',
            '/api/convolab/browser/auth/login',
            ['email' => 'ada@example.com', 'password' => 'wrong password'],
            $csrf,
        )->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $this->assertDatabaseMissing('sessions', ['user_id' => User::query()->sole()->getKey()]);
    }

    public function test_browser_current_user_uses_the_authenticated_web_session(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $csrf = $this->csrfSession();
        $login = $this->statefulJson(
            'POST',
            '/api/convolab/browser/auth/login',
            ['email' => 'ada@example.com', 'password' => 'correct horse battery staple'],
            $csrf,
        )->assertOk();
        $cookies = $this->withAuthenticatedSession($csrf, $login);

        $this->statefulJson('GET', '/api/convolab/browser/auth/me', [], $cookies)
            ->assertOk()
            ->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('seenSampleContentGuide', false)
            ->assertJsonPath('seenCustomContentGuide', false);
    }

    public function test_browser_session_can_use_the_account_compatibility_api_without_a_proxy_header(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $csrf = $this->csrfSession();
        $login = $this->statefulJson(
            'POST',
            '/api/convolab/browser/auth/login',
            ['email' => 'ada@example.com', 'password' => 'correct horse battery staple'],
            $csrf,
        )->assertOk();
        $cookies = $this->withAuthenticatedSession($csrf, $login);

        $this->statefulJson('GET', '/api/convolab/auth/me', [], $cookies)
            ->assertOk()
            ->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('email', 'ada@example.com');

        $this->statefulJson(
            'PATCH',
            '/api/convolab/auth/me',
            ['displayName' => 'Ada Updated'],
            $cookies,
        )->assertOk()
            ->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('displayName', 'Ada Updated');

        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $account['convolab_id'],
            'display_name' => 'Ada Updated',
        ]);
    }

    public function test_account_compatibility_api_ignores_a_spoofed_proxy_identity_header(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com']);
        $other = $this->projectedUser(['email' => 'grace@example.com']);
        $csrf = $this->csrfSession();
        $login = $this->statefulJson(
            'POST',
            '/api/convolab/browser/auth/login',
            ['email' => 'ada@example.com', 'password' => 'correct horse battery staple'],
            $csrf,
        )->assertOk();
        $cookies = $this->withAuthenticatedSession($csrf, $login);

        $this->withHeader('X-Convo-Lab-User-Id', $other['convolab_id']);
        $this->statefulJson('GET', '/api/convolab/auth/me', [], $cookies)
            ->assertOk()
            ->assertJsonPath('id', $account['convolab_id'])
            ->assertJsonPath('email', 'ada@example.com');
    }

    public function test_browser_current_user_ignores_bearer_tokens(): void
    {
        $bearer = User::factory()->create()->createToken('mobile')->plainTextToken;

        $this->withToken($bearer)
            ->getJson('/api/convolab/browser/auth/me')
            ->assertUnauthorized();
    }

    public function test_browser_logout_invalidates_the_authenticated_session_and_rotates_anonymous_state(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);
        $csrf = $this->csrfSession();
        $login = $this->statefulJson(
            'POST',
            '/api/convolab/browser/auth/login',
            ['email' => 'ada@example.com', 'password' => 'correct horse battery staple'],
            $csrf,
        )->assertOk();
        $cookies = $this->withAuthenticatedSession($csrf, $login);
        $sessionId = $cookies['session'];

        $logout = $this->statefulJson(
            'POST',
            '/api/convolab/browser/auth/logout',
            [],
            $cookies,
        )->assertNoContent();
        $anonymousSession = $this->requiredCookie($logout, 'learning_os_session')->getValue();

        $this->assertNotSame($sessionId, $anonymousSession);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseHas('sessions', [
            'id' => $anonymousSession,
        ]);

        Auth::forgetGuards();
        $this->withStatefulHeaders()
            ->withCredentials()
            ->withCookies(['learning_os_session' => $anonymousSession])
            ->getJson('/api/convolab/browser/auth/me')
            ->assertUnauthorized();
    }

    public function test_browser_signup_creates_the_account_and_authenticated_session(): void
    {
        Queue::fake();
        $this->invite('WELCOME1');
        $csrf = $this->csrfSession();

        $response = $this->withoutMiddleware(TrimStrings::class)->statefulJson(
            'POST',
            '/api/convolab/browser/auth/signup',
            [
                'email' => ' ADA@example.com ',
                'password' => 'correct horse battery staple',
                'name' => ' Ada Lovelace ',
                'inviteCode' => ' WELCOME1 ',
            ],
            $csrf,
        )->assertOk()
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonPath('name', 'Ada Lovelace')
            ->assertJsonPath('emailVerified', false);

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $authenticatedSession = $this->requiredCookie($response, 'learning_os_session');

        $this->assertDatabaseHas('sessions', [
            'id' => $authenticatedSession->getValue(),
            'user_id' => $user->getKey(),
        ]);
        Queue::assertPushed(
            SendConvoLabVerificationEmail::class,
            fn (SendConvoLabVerificationEmail $job): bool => $job->userId === $user->getKey(),
        );
    }

    public function test_browser_routes_reuse_the_named_auth_rate_limits(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutesByMethod()['POST']);

        $login = $routes->first(
            fn ($route) => $route->uri() === 'api/convolab/browser/auth/login',
        );
        $signup = $routes->first(
            fn ($route) => $route->uri() === 'api/convolab/browser/auth/signup',
        );

        $this->assertNotNull($login);
        $this->assertNotNull($signup);
        $this->assertContains('throttle:convolab-logins', $login->gatherMiddleware());
        $this->assertContains('throttle:convolab-signups', $signup->gatherMiddleware());
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string, session: string}
     */
    private function csrfSession(): array
    {
        $response = $this->withStatefulHeaders()
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent();
        $csrf = $this->requiredCookie($response, 'XSRF-TOKEN')->getValue();
        $session = $this->requiredCookie($response, 'learning_os_session')->getValue();

        $this->flushHeaders();
        $this->defaultCookies = [];

        return [
            'cookies' => [
                'XSRF-TOKEN' => $csrf,
                'learning_os_session' => $session,
            ],
            'csrf' => $csrf,
            'session' => $session,
        ];
    }

    /**
     * @param  array{cookies: array<string, string>, csrf: string, session: string}  $session
     */
    private function statefulJson(
        string $method,
        string $path,
        array $payload,
        array $session,
    ) {
        return $this->withStatefulHeaders()
            ->withCredentials()
            ->withHeader('X-XSRF-TOKEN', $session['csrf'])
            ->withCookies($session['cookies'])
            ->json($method, $path, $payload);
    }

    /**
     * @param  array{cookies: array<string, string>, csrf: string, session: string}  $csrf
     * @return array{cookies: array<string, string>, csrf: string, session: string}
     */
    private function withAuthenticatedSession(array $csrf, $login): array
    {
        $session = $this->requiredCookie($login, 'learning_os_session')->getValue();
        Auth::forgetGuards();

        return [
            'cookies' => [
                'XSRF-TOKEN' => $csrf['csrf'],
                'learning_os_session' => $session,
            ],
            'csrf' => $csrf['csrf'],
            'session' => $session,
        ];
    }

    private function withStatefulHeaders(): static
    {
        return $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/');
    }

    private function requiredCookie($response, string $name): Cookie
    {
        $cookie = $response->getCookie($name);

        $this->assertInstanceOf(Cookie::class, $cookie, "Missing cookie [{$name}].");

        return $cookie;
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
            'created_at' => '2026-07-20 10:00:00.123',
            'updated_at' => '2026-07-20 11:00:00.456',
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
}
