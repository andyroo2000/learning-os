<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ListAccessTokensApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_access_tokens_for_the_authenticated_user(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04 12:00:00'));
        $user = User::factory()->create();
        $olderToken = $user->createToken('Ada iPhone', ['*'], now()->addDays(30))->accessToken;
        $olderToken->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'last_used_at' => now()->subHour(),
        ])->save();

        $currentAccessToken = $user->createToken('Ada iPad', ['cards:read'], now()->addDays(60));
        $currentToken = $currentAccessToken->accessToken;
        $otherUser = User::factory()->create();
        $otherUser->createToken('Grace iPhone');

        $response = $this
            ->withToken($currentAccessToken->plainTextToken)
            ->getJson('/api/auth/tokens');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $currentToken->id)
            ->assertJsonPath('data.0.name', 'Ada iPad')
            ->assertJsonPath('data.0.abilities', ['cards:read'])
            ->assertJsonPath('data.0.last_used_at', now()->toJSON())
            ->assertJsonPath('data.0.expires_at', now()->addDays(60)->toJSON())
            ->assertJsonPath('data.0.created_at', now()->toJSON())
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.id', $olderToken->id)
            ->assertJsonPath('data.1.name', 'Ada iPhone')
            ->assertJsonPath('data.1.abilities', ['*'])
            ->assertJsonPath('data.1.last_used_at', now()->subHour()->toJSON())
            ->assertJsonPath('data.1.expires_at', now()->addDays(30)->toJSON())
            ->assertJsonPath('data.1.created_at', now()->subDay()->toJSON())
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonMissingPath('data.0.token')
            ->assertJsonMissingPath('data.1.token');
    }

    public function test_first_party_session_auth_lists_tokens_without_a_current_bearer_token(): void
    {
        $user = $this->signIn();
        $token = $user->createToken('Ada iPhone')->accessToken;

        $response = $this->getJson('/api/auth/tokens');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $token->id)
            ->assertJsonPath('data.0.is_current', false)
            ->assertJsonMissingPath('data.0.token');
    }

    public function test_it_uses_token_id_as_a_stable_tiebreaker_for_equal_created_timestamps(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04 12:00:00'));
        $user = User::factory()->create();
        $firstAccessToken = $user->createToken('Ada iPhone');
        $firstToken = $firstAccessToken->accessToken;
        $secondToken = $user->createToken('Ada iPad')->accessToken;

        $response = $this
            ->withToken($firstAccessToken->plainTextToken)
            ->getJson('/api/auth/tokens');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $secondToken->id)
            ->assertJsonPath('data.1.id', $firstToken->id);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/tokens');

        $response->assertUnauthorized();
    }
}
