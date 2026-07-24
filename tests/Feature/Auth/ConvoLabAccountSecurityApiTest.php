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

    public function test_password_update_targets_the_authenticated_user_and_preserves_device_tokens(): void
    {
        $target = $this->projectedUser(password: 'old-password123');
        $target->forceFill(['convolab_password_hash' => Hash::make('old-password123')])->save();
        $targetToken = $target->createToken('existing-device')->plainTextToken;

        $this->asConvoLabBrowser($target, convoLabUserId: (string) $target->convolab_id)
            ->withoutMiddleware(TrimStrings::class)
            ->putJson('/api/convolab/auth/me/password', [
                'current_password' => 'old-password123',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertNoContent();

        $target->refresh();

        $this->assertTrue(Hash::check('new-password123', $target->password));
        $this->assertTrue(Hash::check('new-password123', $target->getAttribute('convolab_password_hash')));
        $this->assertFalse(Hash::check('old-password123', $target->password));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $target->id,
            'name' => 'existing-device',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($targetToken)
            ->withoutHeader('Origin')
            ->withoutHeader('Referer')
            ->getJson('/api/me')
            ->assertOk();
    }

    public function test_account_delete_targets_the_authenticated_user(): void
    {
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

        $this->asConvoLabBrowser($target, convoLabUserId: (string) $target->convolab_id)
            ->deleteJson('/api/convolab/auth/me', ['current_password' => 'correct-password123'])
            ->assertNoContent()
            ->assertContent('');

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('admin_user_projections', ['convolab_id' => $target->convolab_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetTokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    public function test_invalid_current_passwords_do_not_mutate_the_authenticated_user(): void
    {
        $target = $this->projectedUser(password: 'old-password123');
        $this->asConvoLabBrowser($target, convoLabUserId: (string) $target->convolab_id);

        $this
            ->putJson('/api/convolab/auth/me/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this
            ->deleteJson('/api/convolab/auth/me', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password123', $target->refresh()->password));
        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $target->convolab_id]);
    }

    public function test_security_routes_require_a_first_party_browser_session(): void
    {
        $target = $this->projectedUser();
        $ordinary = User::factory()->create(['email' => 'ordinary@example.com']);
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
                ->json($method, $path, $payload)
                ->assertUnauthorized();

            $this->app['auth']->forgetGuards();
            $this->withToken($ordinary->createToken('mobile', ['auth:write'])->plainTextToken)
                ->json($method, $path, $payload)
                ->assertForbidden();
        }
    }

    public function test_security_routes_validate_payloads(): void
    {
        $target = $this->projectedUser();
        $this->asConvoLabBrowser($target, convoLabUserId: (string) $target->convolab_id);

        $this->putJson('/api/convolab/auth/me/password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password']);

        $this->deleteJson('/api/convolab/auth/me', ['current_password' => ['invalid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_password_rate_limit_is_per_target_and_separate_from_deletion(): void
    {
        $first = $this->projectedUser(['email' => 'first@example.com']);
        $second = $this->projectedUser(['email' => 'second@example.com']);
        $payload = [
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ];

        $this->asConvoLabBrowser($first, convoLabUserId: (string) $first->convolab_id);
        foreach (range(1, 5) as $_attempt) {
            $this->putJson('/api/convolab/auth/me/password', $payload)
                ->assertUnprocessable();
        }

        $this->asConvoLabBrowser($first, convoLabUserId: strtoupper((string) $first->convolab_id))
            ->putJson('/api/convolab/auth/me/password', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($second, convoLabUserId: (string) $second->convolab_id)
            ->putJson('/api/convolab/auth/me/password', $payload)
            ->assertUnprocessable();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($first, convoLabUserId: (string) $first->convolab_id)
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
}
