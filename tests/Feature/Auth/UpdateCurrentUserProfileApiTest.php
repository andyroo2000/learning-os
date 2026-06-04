<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCurrentUserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_authenticated_users_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => ' Ada Lovelace ',
                'email' => ' ADA.LOVELACE@example.com ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada.lovelace@example.com')
            ->assertJsonPath('data.email_verified_at', null)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user->refresh();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada.lovelace@example.com', $user->email);
        $this->assertNull($user->email_verified_at);

        $this
            ->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada.lovelace@example.com');
    }

    public function test_it_preserves_email_verification_when_email_is_unchanged(): void
    {
        $verifiedAt = now()->startOfSecond();
        $user = User::factory()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'email_verified_at' => $verifiedAt,
        ]);
        $token = $user->createToken('Grace iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => 'Amazing Grace',
                'email' => ' GRACE@example.com ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Amazing Grace')
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonPath('data.email_verified_at', $verifiedAt->toJSON());

        $this->assertSame($verifiedAt->toJSON(), $user->refresh()->email_verified_at?->toJSON());
    }

    public function test_it_rejects_duplicate_email_after_normalization(): void
    {
        User::factory()->create([
            'email' => 'taken@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => 'Ada Lovelace',
                'email' => ' TAKEN@example.com ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $user->refresh();

        $this->assertSame('ada@example.com', $user->email);
    }

    public function test_it_validates_the_profile_payload(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => '',
                'email' => 'not-an-email',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->putJson('/api/me', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $response->assertUnauthorized();
    }
}
