<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyCurrentAccessTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_revokes_the_presented_bearer_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('Ada iPhone');
        $otherToken = $user->createToken('Ada iPad');

        $response = $this
            ->withToken($currentToken->plainTextToken)
            ->deleteJson('/api/auth/tokens/current');

        $response->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($currentToken->plainTextToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_it_does_not_revoke_other_users_tokens(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $currentToken = $user->createToken('Ada iPhone');
        $otherUserToken = $otherUser->createToken('Grace iPhone');

        $response = $this
            ->withToken($currentToken->plainTextToken)
            ->deleteJson('/api/auth/tokens/current');

        $response->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherUserToken->accessToken->id,
        ]);
    }

    public function test_it_is_a_noop_for_first_party_session_tokens(): void
    {
        $user = $this->signIn();
        $storedToken = $user->createToken('Ada iPhone');

        $response = $this->deleteJson('/api/auth/tokens/current');

        $response->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $storedToken->accessToken->id,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/auth/tokens/current');

        $response->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
