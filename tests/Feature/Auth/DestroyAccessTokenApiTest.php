<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyAccessTokenApiTest extends TestCase
{
    use RefreshDatabase;

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
