<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpdateCurrentUserPasswordApiTest extends TestCase
{
    use RefreshDatabase;

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
