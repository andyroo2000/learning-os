<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\ResolveConvoLabUserAction;
use App\Domain\Auth\Support\ConvoLabAccountSecurityRateLimiter;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabAccountSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_password_update_targets_the_header_user_and_preserves_the_proxy_account(): void
    {
        $proxy = $this->proxyUser();
        $target = $this->projectedUser(password: 'old-password123');
        $target->forceFill(['convolab_password_hash' => Hash::make('old-password123')])->save();
        $targetToken = $target->createToken('existing-device')->plainTextToken;

        $this->withoutMiddleware(TrimStrings::class)
            ->withToken($this->proxyToken(['auth:write'], $proxy))
            ->withHeader('X-Convo-Lab-User-Id', " \t".strtoupper((string) $target->convolab_id)."\n ")
            ->putJson('/api/convolab/auth/me/password', [
                'current_password' => 'old-password123',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertNoContent();

        $target->refresh();
        $proxy->refresh();

        $this->assertTrue(Hash::check('new-password123', $target->password));
        $this->assertTrue(Hash::check('new-password123', $target->getAttribute('convolab_password_hash')));
        $this->assertFalse(Hash::check('old-password123', $target->password));
        $this->assertTrue(Hash::check('proxy-password123', $proxy->password));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $target->id,
            'name' => 'existing-device',
        ]);

        $this->withToken($targetToken)->getJson('/api/me')->assertOk();
    }

    public function test_account_delete_targets_the_header_user_and_preserves_the_proxy_account(): void
    {
        $proxy = $this->proxyUser();
        $proxyToken = $this->proxyToken(['auth:write'], $proxy);
        $target = $this->projectedUser(password: 'correct-password123');
        $targetTokenId = $target->createToken('target-device')->accessToken->getKey();

        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email,
            'token' => 'reset-token',
            'created_at' => now(),
        ]);

        $this->withToken($proxyToken)
            ->withHeader('X-Convo-Lab-User-Id', (string) $target->convolab_id)
            ->deleteJson('/api/convolab/auth/me', ['current_password' => 'correct-password123'])
            ->assertNoContent()
            ->assertContent('');

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('admin_user_projections', ['convolab_id' => $target->convolab_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetTokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseHas('users', ['id' => $proxy->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($proxyToken)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->deleteJson('/api/convolab/auth/me', ['current_password' => 'irrelevant'])
            ->assertNotFound();
    }

    public function test_invalid_current_passwords_do_not_mutate_the_target_or_proxy(): void
    {
        $proxy = $this->proxyUser();
        $target = $this->projectedUser(password: 'old-password123');
        $token = $this->proxyToken(['auth:write'], $proxy);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) $target->convolab_id)
            ->putJson('/api/convolab/auth/me/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) $target->convolab_id)
            ->deleteJson('/api/convolab/auth/me', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password123', $target->refresh()->password));
        $this->assertTrue(Hash::check('proxy-password123', $proxy->refresh()->password));
        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $target->convolab_id]);
    }

    public function test_security_routes_require_the_named_proxy_identity_and_exact_write_scope(): void
    {
        $target = $this->projectedUser();
        $ordinary = User::factory()->create(['email' => 'ordinary@example.com']);
        $wildcard = User::factory()->create(['email' => 'wildcard@example.com']);
        $requests = [
            ['PUT', '/api/convolab/auth/me/password', [
                'current_password' => 'old-password123',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]],
            ['DELETE', '/api/convolab/auth/me', ['current_password' => 'old-password123']],
        ];

        foreach ($requests as [$method, $path, $payload]) {
            $this->app['auth']->forgetGuards();
            $this->withHeaders(['Authorization' => ''])
                ->json($method, $path, $payload, ['X-Convo-Lab-User-Id' => $target->convolab_id])
                ->assertUnauthorized();

            $this->app['auth']->forgetGuards();
            $this->withToken($this->proxyToken(['auth:read']))
                ->json($method, $path, $payload, ['X-Convo-Lab-User-Id' => $target->convolab_id])
                ->assertForbidden();

            $this->app['auth']->forgetGuards();
            $this->withToken($ordinary->createToken('mobile', ['auth:write'])->plainTextToken)
                ->json($method, $path, $payload, ['X-Convo-Lab-User-Id' => $target->convolab_id])
                ->assertForbidden();

            config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
            $this->app['auth']->forgetGuards();
            $this->withToken($wildcard->createToken('convolab-proxy', ['*'])->plainTextToken)
                ->json($method, $path, $payload, ['X-Convo-Lab-User-Id' => $target->convolab_id])
                ->assertForbidden();
            config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        }
    }

    public function test_security_routes_validate_the_target_header_and_payloads(): void
    {
        $token = $this->proxyToken(['auth:write']);

        $this->withToken($token)
            ->putJson('/api/convolab/auth/me/password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId', 'current_password', 'password']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->deleteJson('/api/convolab/auth/me', ['current_password' => ['invalid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId', 'current_password']);
    }

    public function test_password_rate_limit_is_per_target_and_separate_from_deletion(): void
    {
        $first = $this->projectedUser(['email' => 'first@example.com']);
        $second = $this->projectedUser(['email' => 'second@example.com']);
        $token = $this->proxyToken(['auth:write']);
        $payload = [
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ];

        foreach (range(1, 5) as $_attempt) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', (string) $first->convolab_id)
                ->putJson('/api/convolab/auth/me/password', $payload)
                ->assertUnprocessable();
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', strtoupper((string) $first->convolab_id))
            ->putJson('/api/convolab/auth/me/password', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) $second->convolab_id)
            ->putJson('/api/convolab/auth/me/password', $payload)
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) $first->convolab_id)
            ->deleteJson('/api/convolab/auth/me', ['current_password' => 'old-password123'])
            ->assertNoContent();
    }

    public function test_routes_use_dedicated_compatibility_rate_limiters(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $password = $routes->first(fn ($route) => $route->uri() === 'api/convolab/auth/me/password' && in_array('PUT', $route->methods(), true));
        $delete = $routes->first(fn ($route) => $route->uri() === 'api/convolab/auth/me' && in_array('DELETE', $route->methods(), true));

        $this->assertNotNull($password);
        $this->assertNotNull($delete);
        $this->assertContains('throttle:'.ConvoLabAccountSecurityRateLimiter::PASSWORD_UPDATE, $password->gatherMiddleware());
        $this->assertContains('throttle:'.ConvoLabAccountSecurityRateLimiter::ACCOUNT_DELETE, $delete->gatherMiddleware());
    }

    public function test_resolver_rejects_malformed_ids_before_querying(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(ResolveConvoLabUserAction::class)->handle('not-a-uuid');
    }

    public function test_resolver_returns_not_found_for_a_valid_unknown_id(): void
    {
        try {
            app(ResolveConvoLabUserAction::class)->handle((string) Str::uuid());
            $this->fail('Expected the unknown Convo Lab user to be hidden behind a 404.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(User::class, $e->getModel());
        }
    }

    /** @param array<string, mixed> $projectionAttributes */
    private function projectedUser(array $projectionAttributes = [], string $password = 'old-password123'): User
    {
        $convoLabId = (string) Str::uuid();
        $user = User::factory()->create([
            'email' => $projectionAttributes['email'] ?? 'user-'.Str::lower(Str::random(8)).'@example.com',
            'password' => $password,
        ]);
        $user->forceFill(['convolab_id' => $convoLabId])->save();

        DB::table('admin_user_projections')->insert(array_merge([
            'convolab_id' => $convoLabId,
            'user_id' => $user->id,
            'email' => $user->email,
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
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'source_system' => ConvoLabAccountSource::CONVOLAB,
        ], $projectionAttributes));

        return $user->refresh();
    }

    private function proxyUser(): User
    {
        return User::query()->where('email', 'proxy@example.com')->first()
            ?? User::factory()->create([
                'email' => 'proxy@example.com',
                'password' => 'proxy-password123',
            ]);
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities, ?User $proxy = null): string
    {
        return ($proxy ?? $this->proxyUser())
            ->createToken('convolab-proxy', $abilities)
            ->plainTextToken;
    }
}
