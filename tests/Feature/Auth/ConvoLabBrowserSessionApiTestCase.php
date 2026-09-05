<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

abstract class ConvoLabBrowserSessionApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const FRONTEND_ORIGIN = 'https://convo-lab.test';

    private const NODE_BCRYPT_HASH = '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['convo-lab.test']);
        config()->set('session.driver', 'database');
        config()->set('session.cookie', 'learning_os_session');
        config()->set('session.secure', true);
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string, session: string}
     */
    protected function csrfSession(): array
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
    protected function statefulJson(
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
    protected function withAuthenticatedSession(array $csrf, $login): array
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

    protected function withStatefulHeaders(): static
    {
        return $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/');
    }

    protected function requiredCookie($response, string $name): Cookie
    {
        $cookie = $response->getCookie($name);

        $this->assertInstanceOf(Cookie::class, $cookie, "Missing cookie [{$name}].");

        return $cookie;
    }

    /** @param array<string, mixed> $attributes */
    protected function projectedUser(array $attributes = []): array
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

    protected function invite(string $code): void
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
