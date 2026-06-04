<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowCurrentUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_authenticated_user(): void
    {
        $user = $this->signIn(User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
        ]));

        $response = $this->getJson('/api/me');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.email_verified_at', $user->email_verified_at?->toJSON())
            ->assertJsonMissingPath('data.created_at')
            ->assertJsonMissingPath('data.updated_at')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_it_returns_null_for_unverified_email(): void
    {
        $this->signIn(User::factory()->unverified()->create());

        $response = $this->getJson('/api/me');

        $response
            ->assertOk()
            ->assertJsonPath('data.email_verified_at', null);
    }

    public function test_it_accepts_a_sanctum_bearer_token(): void
    {
        $user = User::factory()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
        ]);
        $token = $user->createToken('mobile-test')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/me');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Grace Hopper')
            ->assertJsonPath('data.email', 'grace@example.com');
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertUnauthorized();
    }
}
