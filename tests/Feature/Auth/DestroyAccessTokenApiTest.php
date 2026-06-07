<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\Concerns\UsesAuthAccountRateLimitOverrides;
use Tests\TestCase;

class DestroyAccessTokenApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesAuthAccountRateLimitOverrides;

    public function test_it_revokes_an_owned_access_token_by_id(): void
    {
        $user = User::factory()->create();
        $currentAccessToken = $user->createToken('Ada iPhone');
        $tokenToRevoke = $user->createToken('Ada iPad')->accessToken;
        $otherToken = $user->createToken('Ada Mac')->accessToken;

        $response = $this
            ->withToken($currentAccessToken->plainTextToken)
            ->deleteJson("/api/auth/tokens/{$tokenToRevoke->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenToRevoke->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentAccessToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->id,
        ]);
    }

    public function test_it_can_revoke_the_current_access_token_by_id(): void
    {
        $user = User::factory()->create();
        $currentAccessToken = $user->createToken('Ada iPhone');

        $response = $this
            ->withToken($currentAccessToken->plainTextToken)
            ->deleteJson("/api/auth/tokens/{$currentAccessToken->accessToken->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentAccessToken->accessToken->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($currentAccessToken->plainTextToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_it_is_idempotent_for_missing_or_cross_user_tokens(): void
    {
        $user = User::factory()->create();
        $currentAccessToken = $user->createToken('Ada iPhone');
        $otherUser = User::factory()->create();
        $otherUserToken = $otherUser->createToken('Grace iPhone')->accessToken;

        $this
            ->withToken($currentAccessToken->plainTextToken)
            ->deleteJson('/api/auth/tokens/999999')
            ->assertNoContent();

        $this
            ->withToken($currentAccessToken->plainTextToken)
            ->deleteJson("/api/auth/tokens/{$otherUserToken->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentAccessToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherUserToken->id,
        ]);
    }

    public function test_it_rate_limits_token_revokes_by_user_across_current_and_by_id_routes(): void
    {
        $user = User::factory()->create();
        $currentAccessToken = $user->createToken('Ada iPhone');
        $firstTokenToRevoke = $user->createToken('Ada iPad')->accessToken;
        $secondTokenToRevoke = $user->createToken('Ada Mac')->accessToken;
        $otherUser = User::factory()->create();
        $otherCurrentAccessToken = $otherUser->createToken('Grace iPhone');
        $otherTokenToRevoke = $otherUser->createToken('Grace iPad')->accessToken;

        $this->withAuthAccountRateLimitOverride(
            AuthAccountRateLimiter::TOKEN_REVOKE,
            [$user->id, $otherUser->id],
            function () use ($currentAccessToken, $firstTokenToRevoke, $otherCurrentAccessToken, $otherTokenToRevoke, $secondTokenToRevoke, $user): void {
                $this
                    ->withToken($currentAccessToken->plainTextToken)
                    ->deleteJson("/api/auth/tokens/{$firstTokenToRevoke->id}")
                    ->assertNoContent();

                $this
                    ->withToken($currentAccessToken->plainTextToken)
                    ->deleteJson("/api/auth/tokens/{$secondTokenToRevoke->id}")
                    ->assertNoContent();

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($otherCurrentAccessToken->plainTextToken)
                    ->deleteJson("/api/auth/tokens/{$otherTokenToRevoke->id}")
                    ->assertNoContent();

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($currentAccessToken->plainTextToken)
                    ->deleteJson('/api/auth/tokens/current')
                    ->assertTooManyRequests()
                    ->assertJsonPath('message', 'Too Many Attempts.')
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this->assertDatabaseMissing('personal_access_tokens', [
                    'id' => $firstTokenToRevoke->id,
                ]);
                $this->assertDatabaseMissing('personal_access_tokens', [
                    'id' => $secondTokenToRevoke->id,
                ]);
                $this->assertDatabaseHas('personal_access_tokens', [
                    'id' => $currentAccessToken->accessToken->id,
                ]);
                $this->assertDatabaseMissing('personal_access_tokens', [
                    'id' => $otherTokenToRevoke->id,
                ]);

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($currentAccessToken->plainTextToken)
                    ->getJson('/api/me')
                    ->assertOk()
                    ->assertJsonPath('data.id', $user->id);
            },
        );
    }

    public function test_it_rejects_non_numeric_token_ids(): void
    {
        $user = User::factory()->create();
        $currentAccessToken = $user->createToken('Ada iPhone');

        $response = $this
            ->withToken($currentAccessToken->plainTextToken)
            ->deleteJson('/api/auth/tokens/not-a-number');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/auth/tokens/1');

        $response->assertUnauthorized();
    }
}
