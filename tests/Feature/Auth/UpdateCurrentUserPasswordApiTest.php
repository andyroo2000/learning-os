<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Auth\Concerns\UsesAuthAccountRateLimitOverrides;
use Tests\TestCase;

class UpdateCurrentUserPasswordApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesAuthAccountRateLimitOverrides;

    public function test_it_updates_the_authenticated_users_password(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password123',
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me/password', [
                'current_password' => 'old-password123',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        $response->assertNoContent();

        $user->refresh();

        $this->assertTrue(Hash::check('new-password123', $user->password));
        $this->assertFalse(Hash::check('old-password123', $user->password));

        $this
            ->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');

        $this
            ->postJson('/api/auth/tokens', [
                'email' => 'ada@example.com',
                'password' => 'old-password123',
                'device_name' => 'Ada iPad',
            ])
            ->assertUnauthorized();

        $this
            ->postJson('/api/auth/tokens', [
                'email' => 'ada@example.com',
                'password' => 'new-password123',
                'device_name' => 'Ada iPad',
            ])
            ->assertCreated();
    }

    public function test_it_rejects_an_invalid_current_password_without_updating(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password123',
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;
        $originalPasswordHash = $user->password;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertSame($originalPasswordHash, $user->refresh()->password);
    }

    public function test_it_rate_limits_password_updates_by_user(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password123',
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;
        $otherUser = User::factory()->create([
            'password' => 'other-password123',
        ]);
        $otherToken = $otherUser->createToken('Grace iPhone')->plainTextToken;

        $this->withAuthAccountRateLimitOverride(
            AuthAccountRateLimiter::PASSWORD_UPDATE,
            [$user->id, $otherUser->id],
            function () use ($otherToken, $token, $user): void {
                $this
                    ->withToken($token)
                    ->putJson('/api/me/password', [
                        'current_password' => 'old-password123',
                        'password' => 'new-password123',
                        'password_confirmation' => 'new-password123',
                    ])
                    ->assertNoContent();

                $this
                    ->withToken($token)
                    ->putJson('/api/me/password', [
                        'current_password' => 'new-password123',
                        'password' => 'newer-password123',
                        'password_confirmation' => 'newer-password123',
                    ])
                    ->assertNoContent();

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($otherToken)
                    ->putJson('/api/me/password', [
                        'current_password' => 'other-password123',
                        'password' => 'other-new-password123',
                        'password_confirmation' => 'other-new-password123',
                    ])
                    ->assertNoContent();

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($token)
                    ->putJson('/api/me/password', [
                        'current_password' => 'newer-password123',
                        'password' => 'blocked-password123',
                        'password_confirmation' => 'blocked-password123',
                    ])
                    ->assertTooManyRequests()
                    ->assertJsonPath('message', 'Too Many Attempts.')
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this->assertTrue(Hash::check('newer-password123', $user->refresh()->password));
                $this->assertFalse(Hash::check('blocked-password123', $user->password));

                $this
                    ->withToken($token)
                    ->getJson('/api/me')
                    ->assertOk();
            },
        );
    }

    public function test_it_validates_the_payload(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me/password', [
                'current_password' => '',
                'password' => 'short',
                'password_confirmation' => 'different',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'current_password',
                'password',
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->putJson('/api/me/password', [
            'current_password' => 'old-password123',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertUnauthorized();
    }
}
